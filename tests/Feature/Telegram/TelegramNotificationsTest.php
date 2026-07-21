<?php

use App\Contracts\Telegram\Sleeper;
use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\Telegram\TelegramContext;
use App\Models\TelegramNotification;
use App\Services\Telegram\TelegramContextResolver;
use App\Services\Telegram\TelegramNotificationDispatcher;
use App\Services\Telegram\TelegramNotificationSender;
use App\Services\Telegram\TelegramNotificationSubscriptions;
use App\Services\Telegram\TelegramRateLimiter;
use App\Telegram\Handlers\NotificationSwitchCommandHandler;
use App\Telegram\Handlers\NotificationToggleCallbackHandler;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use App\Telegram\Messages\SettingsRichMessageFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\Method\SendChatAction;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Type\Chat;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;

uses(RefreshDatabase::class);

function telegramUpdate(array $payload): Update
{
    return Update::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
}

function telegramMessage(int $chatId = 1): Message
{
    return new Message(1, new DateTimeImmutable('@0'), new Chat($chatId, 'private'));
}

test('notification migration and model use recurring defaults and signed bigint contexts', function () {
    $notification = TelegramNotification::query()->create(['instance_id' => -1001234567890]);

    expect($notification->instance_id)->toBe(-1001234567890)
        ->and($notification->thread_id)->toBeNull()
        ->and($notification->send_time)->toBe('00:00:00')
        ->and($notification->enabled)->toBeFalse()
        ->and($notification->last_sent_at)->toBeNull()
        ->and(Schema::hasColumns('telegram_notifications', [
            'id', 'instance_id', 'thread_id', 'context_key', 'send_time', 'enabled', 'last_sent_at', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $columns = collect(DB::select("PRAGMA table_info('telegram_notifications')"))->keyBy('name');
    // SQLite aliases BIGINT to INTEGER; the migration uses Blueprint::bigInteger.
    expect(strtoupper($columns['instance_id']->type))->toBe('INTEGER')
        ->and(strtoupper($columns['thread_id']->type))->toBe('INTEGER')
        ->and(strtoupper($columns['send_time']->type))->toContain('TIME');
});

test('root chat subscriptions have null safe database uniqueness', function () {
    TelegramNotification::query()->create(['instance_id' => -1001234567890]);

    expect(fn () => TelegramNotification::query()->create(['instance_id' => -1001234567890]))
        ->toThrow(QueryException::class);
});

test('context resolver supports messages channels topics and callback messages', function (string $key, int $chat, ?int $thread) {
    $message = [
        'message_id' => 10,
        'date' => 1,
        'chat' => ['id' => $chat, 'type' => $key === 'channel_post' ? 'channel' : 'supergroup'],
        ...($thread === null ? [] : ['message_thread_id' => $thread, 'is_topic_message' => true]),
    ];
    $payload = $key === 'callback_query'
        ? ['update_id' => 1, $key => [
            'id' => 'callback', 'from' => ['id' => 2, 'is_bot' => false, 'first_name' => 'Test'],
            'chat_instance' => 'instance', 'data' => 'notifications:toggle', 'message' => $message,
        ]]
        : ['update_id' => 1, $key => $message];

    expect((new TelegramContextResolver)->resolve(telegramUpdate($payload)))
        ->toEqual(new TelegramContext($chat, $thread));
})->with([
    'private message' => ['message', 123456789012, null],
    'group message' => ['message', -123456789012, null],
    'channel post' => ['channel_post', -1001234567890, null],
    'forum topic' => ['message', -1001234567890, 9876543210],
    'callback message' => ['callback_query', -1009988776655, 42],
]);

test('context resolver clearly rejects updates without an accessible message', function () {
    $update = telegramUpdate([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'callback',
            'from' => ['id' => 2, 'is_bot' => false, 'first_name' => 'Test'],
            'chat_instance' => 'instance',
            'inline_message_id' => 'inline',
        ],
    ]);

    expect(fn () => (new TelegramContextResolver)->resolve($update))
        ->toThrow(InvalidArgumentException::class, 'no accessible message/chat context');
});

test('inaccessible callback messages resolve and retain their stored topic subscription', function () {
    $subscription = TelegramNotification::query()->create([
        'instance_id' => -1001234567890,
        'thread_id' => 77,
    ]);
    $update = telegramUpdate([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'callback',
            'from' => ['id' => 2, 'is_bot' => false, 'first_name' => 'Test'],
            'chat_instance' => 'instance',
            'data' => 'notifications:toggle:'.$subscription->id,
            'message' => [
                'message_id' => 10,
                'date' => 0,
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
            ],
        ],
    ]);

    $context = (new TelegramContextResolver)->resolve($update);
    $resolved = (new TelegramNotificationSubscriptions)->findForCallback($subscription->id, $context);

    expect($context)->toEqual(new TelegramContext(-1001234567890, null, false))
        ->and($resolved->is($subscription))->toBeTrue()
        ->and($resolved->thread_id)->toBe(77);
});

test('enable command semantics never toggle off and callback semantics do toggle', function () {
    $subscriptions = new TelegramNotificationSubscriptions;
    $context = new TelegramContext(-1001234567890, 77);
    $beforeSchedule = CarbonImmutable::parse('2026-07-21 09:00:00', 'UTC');
    $subscription = $subscriptions->findOrCreate($context);
    $subscription->update(['send_time' => '10:00:00']);

    expect($subscriptions->enable($context, $beforeSchedule)->enabled)->toBeTrue()
        ->and($subscriptions->enable($context, $beforeSchedule)->enabled)->toBeTrue()
        ->and($subscriptions->toggle($context, $beforeSchedule)->enabled)->toBeFalse()
        ->and($subscriptions->toggle($context, $beforeSchedule)->enabled)->toBeTrue();

    expect(method_exists(NotificationSwitchCommandHandler::class, 'handle'))->toBeTrue()
        ->and(method_exists(NotificationToggleCallbackHandler::class, 'handle'))->toBeTrue();
});

test('enabling after send time suppresses same day catch up', function () {
    $subscriptions = new TelegramNotificationSubscriptions;
    $context = new TelegramContext(123, null);
    $subscription = $subscriptions->findOrCreate($context);
    $subscription->update(['send_time' => '10:00:00']);
    $now = CarbonImmutable::parse('2026-07-21 10:01:00', 'UTC');

    $enabled = $subscriptions->enable($context, $now);

    expect($enabled->last_sent_at?->equalTo($now))->toBeTrue()
        ->and($subscriptions->due($now))->toBeEmpty()
        ->and($subscriptions->due($now->addDay()))->toHaveCount(1);
});

test('settings view contains native player table status time and inline keyboard', function () {
    $subscription = TelegramNotification::query()->create([
        'instance_id' => 1, 'send_time' => '14:30:00', 'enabled' => true,
    ]);
    $analytics = new LeaderboardAnalyticsData([
        new PlayerCountTrendData('day', 100, 90, 10),
        new PlayerCountTrendData('week', 200, null, null),
        new PlayerCountTrendData('month', 300, 350, -50),
    ], [], []);

    $view = (new SettingsRichMessageFactory)->make($analytics, $subscription);

    expect($view->message->blocks)->toHaveCount(4)
        ->and($view->message->blocks[2]->cells)->toHaveCount(4)
        ->and($view->keyboard->toRequestArray())->toBe([
            'inline_keyboard' => [[
                ['text' => '🔕 Выключить', 'callback_data' => 'notifications:toggle:'.$subscription->id],
                ['text' => '🔄 Обновить', 'callback_data' => 'notifications:refresh:'.$subscription->id],
            ]],
        ]);
});

test('due query is idempotent across UTC days', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create([
        'instance_id' => 1, 'enabled' => true, 'send_time' => '12:30:00',
    ]);
    TelegramNotification::query()->create([
        'instance_id' => 2, 'enabled' => true, 'send_time' => '12:31:00',
    ]);
    TelegramNotification::query()->create([
        'instance_id' => 3, 'enabled' => true, 'send_time' => '00:00:00', 'last_sent_at' => $now,
    ]);

    $subscriptions = new TelegramNotificationSubscriptions;

    expect($subscriptions->due($now)->pluck('instance_id')->all())->toBe([1])
        ->and($subscriptions->due($now->addDay())->pluck('instance_id')->all())->toBe([1, 3])
        ->and($subscriptions->due($now->addDay()->addMinute())->pluck('instance_id')->all())->toBe([1, 2, 3]);
});

test('dispatcher builds once continues failures marks only successes and passes thread id', function () {
    config()->set('telegram_notifications.rate_limit.cache_store', 'array');
    config()->set('telegram_notifications.rate_limit.global_per_second', 1000);
    config()->set('telegram_notifications.rate_limit.same_chat_interval_ms', 0);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    $subscriptions = new Collection([
        TelegramNotification::query()->create(['instance_id' => 1, 'thread_id' => 101, 'enabled' => true]),
        TelegramNotification::query()->create(['instance_id' => 2, 'thread_id' => 202, 'enabled' => true]),
        TelegramNotification::query()->create(['instance_id' => 3, 'thread_id' => 303, 'enabled' => true]),
    ]);
    $gateway = new class implements TelegramRichMessageGateway
    {
        /** @var list<int|null> */
        public array $threads = [];

        public function send(int $chatId, InputRichMessage $message, ?int $messageThreadId = null, ?InlineKeyboardMarkup $keyboard = null): FailResult|Message
        {
            $this->threads[] = $messageThreadId;

            return match ($chatId) {
                1 => telegramMessage($chatId),
                2 => new FailResult(new SendChatAction(2, 'typing'), new ApiResponse(429, '{}'), 'Too Many Requests', errorCode: 429),
                default => throw new RuntimeException('network down'),
            };
        }

        public function updateMessage(InputRichMessage $message, ?InlineKeyboardMarkup $keyboard = null): FailResult|Message|true
        {
            return true;
        }
    };
    $repository = Mockery::mock(TelegramNotificationSubscriptions::class);
    $repository->shouldReceive('due')->once()->with(Mockery::on(
        fn (CarbonImmutable $value): bool => $value->equalTo($now),
    ))->andReturn($subscriptions);
    $digest = new InputRichMessage(blocks: []);
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn($digest);
    CarbonImmutable::setTestNow($now);
    $sender = new TelegramNotificationSender($gateway);
    $dispatcher = new TelegramNotificationDispatcher($repository, $builder, $sender);

    expect($dispatcher->dispatch($now))->toBe(1)
        ->and($subscriptions[0]->refresh()->last_sent_at?->equalTo($now))->toBeTrue()
        ->and($subscriptions[1]->refresh()->last_sent_at)->toBeNull()
        ->and($subscriptions[2]->refresh()->last_sent_at)->toBeNull()
        ->and($gateway->threads)->toBe([101, 202, 303]);

    CarbonImmutable::setTestNow();
});

test('a stale pre midnight dispatch cannot duplicate or move a new day delivery backwards', function () {
    config()->set('telegram_notifications.rate_limit.cache_store', 'array');
    $oldRun = CarbonImmutable::parse('2026-07-21 23:59:00', 'UTC');
    $newRun = $oldRun->addMinute();
    $subscription = TelegramNotification::query()->create([
        'instance_id' => 1,
        'enabled' => true,
        'send_time' => '00:00:00',
    ]);
    $repository = Mockery::mock(TelegramNotificationSubscriptions::class);
    $repository->shouldReceive('due')->twice()->andReturn(new Collection([$subscription]));
    $digest = new InputRichMessage(blocks: []);
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->twice()->andReturn($digest);
    $gateway = new class implements TelegramRichMessageGateway
    {
        public int $calls = 0;

        public function send(int $chatId, InputRichMessage $message, ?int $messageThreadId = null, ?InlineKeyboardMarkup $keyboard = null): FailResult|Message
        {
            $this->calls++;

            return telegramMessage($chatId);
        }

        public function updateMessage(InputRichMessage $message, ?InlineKeyboardMarkup $keyboard = null): FailResult|Message|true
        {
            return true;
        }
    };
    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway),
    );
    CarbonImmutable::setTestNow($newRun);

    expect($dispatcher->dispatch($newRun))->toBe(1)
        ->and($dispatcher->dispatch($oldRun))->toBe(0)
        ->and($gateway->calls)->toBe(1)
        ->and($subscription->refresh()->last_sent_at?->equalTo($newRun))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('rate limiter reservations use an injectable sleeper instead of real sleeps', function () {
    config()->set('telegram_notifications.rate_limit.cache_store', 'array');
    config()->set('telegram_notifications.rate_limit.global_per_second', 25);
    config()->set('telegram_notifications.rate_limit.same_chat_interval_ms', 1000);
    $sleeper = new class implements Sleeper
    {
        /** @var list<int> */
        public array $waits = [];

        public function milliseconds(int $milliseconds): void
        {
            $this->waits[] = $milliseconds;
        }
    };
    $limiter = new TelegramRateLimiter($sleeper);

    $limiter->reserve(999);
    $limiter->reserve(999);

    expect($sleeper->waits[0])->toBe(0)
        ->and($sleeper->waits[1])->toBeGreaterThanOrEqual(990);
});

test('notification artisan command and minutely protected schedule are registered', function () {
    config()->set('cache.default', 'array');

    $this->artisan('telegram:notifications:send')
        ->expectsOutput('Sent 0 Telegram notification(s).')
        ->assertSuccessful();

    $this->artisan('schedule:list')
        ->expectsOutputToContain('telegram:notifications:send')
        ->assertSuccessful();
});
