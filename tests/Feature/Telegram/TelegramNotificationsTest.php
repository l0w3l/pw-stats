<?php

use App\Contracts\Telegram\Sleeper;
use App\Contracts\Telegram\TelegramChatMemberGateway;
use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\Telegram\TelegramContext;
use App\Jobs\SendTelegramNotificationBatch;
use App\Models\TelegramNotification;
use App\Models\TelegramNotificationDelivery;
use App\Queries\LeaderboardAnalytics;
use App\Services\Telegram\TelegramContextResolver;
use App\Services\Telegram\TelegramInboundRateLimiter;
use App\Services\Telegram\TelegramNotificationDispatcher;
use App\Services\Telegram\TelegramNotificationSender;
use App\Services\Telegram\TelegramNotificationSubscriptions;
use App\Services\Telegram\TelegramRateLimiter;
use App\Services\Telegram\TelegramSettings;
use App\Services\Telegram\TelegramSettingsAuthorization;
use App\Telegram\Handlers\NotificationSwitchCommandHandler;
use App\Telegram\Handlers\NotificationToggleCallbackHandler;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use App\Telegram\Messages\SettingsRichMessageFactory;
use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\Method\SendChatAction;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Type\Chat;
use Phptg\BotApi\Type\ChatMember;
use Phptg\BotApi\Type\ChatMemberAdministrator;
use Phptg\BotApi\Type\ChatMemberMember;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\ResponseParameters;
use Phptg\BotApi\Type\Update\Update;
use Phptg\BotApi\Type\User;

uses(RefreshDatabase::class);

function telegramUpdate(array $payload): Update
{
    return Update::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
}

function telegramMessage(int $chatId = 1): Message
{
    return new Message(1, new DateTimeImmutable('@0'), new Chat($chatId, 'private'));
}

function telegramSettings(
    ?ChatMember $member,
    int $analyticsCalls = 0,
    ?TelegramRichMessageGateway $gateway = null,
): TelegramSettings {
    $memberGateway = Mockery::mock(TelegramChatMemberGateway::class);
    if ($member === null) {
        $memberGateway->shouldNotReceive('get');
    } else {
        $memberGateway->shouldReceive('get')->andReturn($member);
    }

    $analytics = Mockery::mock(LeaderboardAnalytics::class);
    $analytics->shouldReceive('get')
        ->times($analyticsCalls)
        ->andReturn(new LeaderboardAnalyticsData([], [], []));
    if ($gateway === null) {
        $gateway = Mockery::mock(TelegramRichMessageGateway::class);
        $gateway->shouldReceive('send')->zeroOrMoreTimes()->andReturnUsing(
            fn (int $chatId): Message => telegramMessage($chatId),
        );
        $gateway->shouldReceive('updateMessage')->zeroOrMoreTimes()->andReturnTrue();
    }

    return new TelegramSettings(
        new TelegramContextResolver,
        new TelegramNotificationSubscriptions,
        $analytics,
        new SettingsRichMessageFactory,
        $gateway,
        new TelegramSettingsAuthorization($memberGateway),
        new TelegramInboundRateLimiter(app(CacheManager::class)),
    );
}

function telegramUser(int $id): User
{
    return new User($id, false, 'Test');
}

function telegramAdministrator(int $id): ChatMemberAdministrator
{
    return new ChatMemberAdministrator(
        telegramUser($id),
        true,
        false,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
    );
}

function telegramSettingsCallback(TelegramNotification $subscription, int $actorId = 1201): Update
{
    $message = [
        'message_id' => 10,
        'date' => 1,
        'chat' => ['id' => $subscription->instance_id, 'type' => 'supergroup'],
    ];

    if ($subscription->thread_id !== null) {
        $message['message_thread_id'] = $subscription->thread_id;
        $message['is_topic_message'] = true;
    }

    return telegramUpdate([
        'update_id' => $actorId,
        'callback_query' => [
            'id' => 'settings-'.$actorId,
            'from' => ['id' => $actorId, 'is_bot' => false, 'first_name' => 'Actor'],
            'chat_instance' => 'settings',
            'data' => 'notifications:toggle:'.$subscription->id,
            'message' => $message,
        ],
    ]);
}

test('notification migration and model use recurring defaults and signed bigint contexts', function () {
    $notification = TelegramNotification::query()->create(['instance_id' => -1001234567890]);

    expect($notification->instance_id)->toBe(-1001234567890)
        ->and($notification->thread_id)->toBeNull()
        ->and($notification->send_time)->toBe('00:00:00')
        ->and($notification->enabled)->toBeFalse()
        ->and($notification->next_send_at)->toBeNull()
        ->and($notification->last_sent_at)->toBeNull()
        ->and(Schema::hasColumns('telegram_notifications', [
            'id', 'instance_id', 'thread_id', 'context_key', 'send_time', 'enabled', 'next_send_at', 'last_sent_at', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $columns = collect(DB::select("PRAGMA table_info('telegram_notifications')"))->keyBy('name');
    // SQLite aliases BIGINT to INTEGER; the migration uses Blueprint::bigInteger.
    expect(strtoupper($columns['instance_id']->type))->toBe('INTEGER')
        ->and(strtoupper($columns['thread_id']->type))->toBe('INTEGER')
        ->and(strtoupper($columns['send_time']->type))->toContain('TIME');
});

test('notification scheduling migration exposes indexed portable scheduling state', function () {
    expect(Schema::hasColumns('telegram_notification_deliveries', [
        'id', 'telegram_notification_id', 'scheduled_for', 'status', 'next_attempt_at',
        'attempt_count', 'claim_token', 'claimed_at', 'claim_expires_at', 'sent_at',
        'failed_at', 'last_error', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $notificationIndexes = collect(Schema::getIndexes('telegram_notifications'))->pluck('name');
    $deliveryIndexes = collect(Schema::getIndexes('telegram_notification_deliveries'))->pluck('name');

    expect($notificationIndexes)->toContain('telegram_notifications_due_index')
        ->and($deliveryIndexes)->toContain('telegram_notification_deliveries_next_attempt_index')
        ->and($deliveryIndexes)->toContain('telegram_notification_deliveries_stale_claim_index')
        ->and($deliveryIndexes)->toContain('telegram_notification_deliveries_occurrence_unique');
});

test('delivery ledger uniquely and durably represents a scheduled occurrence', function () {
    $notification = TelegramNotification::query()->create(['instance_id' => 123]);
    $scheduledFor = CarbonImmutable::parse('2026-07-21 10:00:00', 'UTC');
    $delivery = TelegramNotificationDelivery::query()->create([
        'telegram_notification_id' => $notification->id,
        'scheduled_for' => $scheduledFor,
        'next_attempt_at' => $scheduledFor,
    ]);

    expect($delivery->status)->toBe(TelegramNotificationDelivery::STATUS_PENDING)
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->scheduled_for->equalTo($scheduledFor))->toBeTrue()
        ->and($delivery->notification->is($notification))->toBeTrue();

    expect(fn () => TelegramNotificationDelivery::query()->create([
        'telegram_notification_id' => $notification->id,
        'scheduled_for' => $scheduledFor,
        'next_attempt_at' => $scheduledFor->addMinute(),
    ]))->toThrow(QueryException::class);
});

test('scheduling migration backfills enabled occurrences without disabled or same day catch up', function () {
    $migration = require database_path('migrations/2026_07_21_000007_add_notification_delivery_scheduling.php');
    $migration->down();
    $now = CarbonImmutable::parse('2026-07-21 10:01:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    foreach ([
        [1, true, '12:00:00', null],
        [2, true, '10:00:00', null],
        [3, true, '10:00:00', '2026-07-21 10:00:30'],
        [4, false, '09:00:00', null],
    ] as [$instanceId, $enabled, $sendTime, $lastSentAt]) {
        DB::table('telegram_notifications')->insert([
            'instance_id' => $instanceId,
            'thread_id' => null,
            'context_key' => $instanceId.':root',
            'send_time' => $sendTime,
            'enabled' => $enabled,
            'last_sent_at' => $lastSentAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $migration->up();
    $subscriptions = TelegramNotification::query()->orderBy('instance_id')->get();

    expect($subscriptions[0]->next_send_at?->equalTo(CarbonImmutable::parse('2026-07-21 12:00:00', 'UTC')))->toBeTrue()
        ->and($subscriptions[1]->next_send_at?->equalTo(CarbonImmutable::parse('2026-07-21 10:00:00', 'UTC')))->toBeTrue()
        ->and($subscriptions[2]->next_send_at?->equalTo(CarbonImmutable::parse('2026-07-22 10:00:00', 'UTC')))->toBeTrue()
        ->and($subscriptions[3]->next_send_at)->toBeNull();

    CarbonImmutable::setTestNow();
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

test('private chat settings are explicitly allowed', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $update = telegramUpdate([
        'update_id' => 11,
        'message' => [
            'message_id' => 1,
            'date' => 1,
            'chat' => ['id' => 501, 'type' => 'private'],
            'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'Private'],
            'text' => '/start',
        ],
    ]);

    $subscription = telegramSettings(null, 1)->show($update);

    expect($subscription)->not->toBeNull()
        ->and($subscription?->instance_id)->toBe(501)
        ->and(TelegramNotification::query()->count())->toBe(1);
});

test('normal group members cannot create settings or enable notifications', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $actor = telegramUser(601);
    $member = new ChatMemberMember($actor);
    $message = [
        'message_id' => 1,
        'date' => 1,
        'chat' => ['id' => -601, 'type' => 'supergroup'],
        'from' => ['id' => $actor->id, 'is_bot' => false, 'first_name' => 'Member'],
    ];

    expect(telegramSettings($member)->show(telegramUpdate(['update_id' => 12, 'message' => $message])))->toBeNull()
        ->and(telegramSettings($member)->enable(telegramUpdate(['update_id' => 13, 'message' => $message])))->toBeNull()
        ->and(TelegramNotification::query()->count())->toBe(0);
});

test('group administrators can enable shared settings', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $update = telegramUpdate([
        'update_id' => 14,
        'message' => [
            'message_id' => 1,
            'date' => 1,
            'chat' => ['id' => -701, 'type' => 'group'],
            'from' => ['id' => 701, 'is_bot' => false, 'first_name' => 'Admin'],
        ],
    ]);

    $subscription = telegramSettings(telegramAdministrator(701), 1)->enable($update);

    expect($subscription?->enabled)->toBeTrue()
        ->and($subscription?->instance_id)->toBe(-701);
});

test('topic callbacks require an administrator and retain topic scope', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => -801,
        'thread_id' => 88,
        'enabled' => false,
    ]);
    $callback = static fn (int $actorId, int $updateId): Update => telegramUpdate([
        'update_id' => $updateId,
        'callback_query' => [
            'id' => 'topic-'.$updateId,
            'from' => ['id' => $actorId, 'is_bot' => false, 'first_name' => 'Actor'],
            'chat_instance' => 'topic',
            'data' => 'notifications:toggle:'.$subscription->id,
            'message' => [
                'message_id' => 2,
                'date' => 1,
                'chat' => ['id' => -801, 'type' => 'supergroup'],
                'message_thread_id' => 88,
                'is_topic_message' => true,
            ],
        ],
    ]);

    expect(telegramSettings(new ChatMemberMember(telegramUser(802)))->toggle($callback(802, 15)))->toBeNull()
        ->and($subscription->refresh()->enabled)->toBeFalse();

    $changed = telegramSettings(telegramAdministrator(803), 1)->toggle($callback(803, 16));

    expect($changed?->thread_id)->toBe(88)
        ->and($subscription->refresh()->enabled)->toBeTrue();
});

test('channel posts and anonymous group administrators are deliberately allowed', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $channel = telegramUpdate([
        'update_id' => 17,
        'channel_post' => [
            'message_id' => 1,
            'date' => 1,
            'chat' => ['id' => -901, 'type' => 'channel'],
            'text' => '/notification_switch',
        ],
    ]);
    $anonymous = telegramUpdate([
        'update_id' => 18,
        'message' => [
            'message_id' => 2,
            'date' => 1,
            'chat' => ['id' => -902, 'type' => 'supergroup'],
            'sender_chat' => ['id' => -902, 'type' => 'supergroup'],
            'from' => ['id' => 1087968824, 'is_bot' => true, 'first_name' => 'GroupAnonymousBot'],
        ],
    ]);

    expect(telegramSettings(null, 1)->enable($channel)?->enabled)->toBeTrue()
        ->and(telegramSettings(null, 1)->enable($anonymous)?->enabled)->toBeTrue()
        ->and(TelegramNotification::query()->count())->toBe(2);
});

test('missing actors and untrusted linked channel senders fail closed', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $missing = telegramUpdate([
        'update_id' => 19,
        'message' => [
            'message_id' => 1,
            'date' => 1,
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
        ],
    ]);
    $linkedChannel = telegramUpdate([
        'update_id' => 20,
        'message' => [
            'message_id' => 2,
            'date' => 1,
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'sender_chat' => ['id' => -2002, 'type' => 'channel'],
        ],
    ]);

    expect(telegramSettings(null)->enable($missing))->toBeNull()
        ->and(telegramSettings(null)->enable($linkedChannel))->toBeNull()
        ->and(TelegramNotification::query()->count())->toBe(0);
});

test('inbound actor and chat throttle rejects callbacks before analytics or a second mutation', function () {
    config()->set('telegram_notifications.inbound_rate_limit', [
        'cache_store' => 'array',
        'max_attempts' => 1,
        'decay_seconds' => 60,
    ]);
    $subscription = TelegramNotification::query()->create(['instance_id' => -1101]);
    $update = telegramUpdate([
        'update_id' => 21,
        'callback_query' => [
            'id' => 'throttled',
            'from' => ['id' => 1101, 'is_bot' => false, 'first_name' => 'Admin'],
            'chat_instance' => 'throttle',
            'data' => 'notifications:toggle:'.$subscription->id,
            'message' => [
                'message_id' => 1,
                'date' => 1,
                'chat' => ['id' => -1101, 'type' => 'supergroup'],
            ],
        ],
    ]);
    $settings = telegramSettings(telegramAdministrator(1101), 1);

    expect($settings->toggle($update)?->enabled)->toBeTrue()
        ->and($settings->toggle($update))->toBeNull()
        ->and($subscription->refresh()->enabled)->toBeTrue();
});

test('successful callback edits accept both true and message results without a replacement send', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $first = TelegramNotification::query()->create(['instance_id' => -1201]);
    $second = TelegramNotification::query()->create(['instance_id' => -1202]);
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('updateMessage')
        ->twice()
        ->andReturn(true, telegramMessage(-1202));
    $gateway->shouldNotReceive('send');
    $settings = telegramSettings(telegramAdministrator(1201), 2, $gateway);

    expect($settings->toggle(telegramSettingsCallback($first, 1201))?->enabled)->toBeTrue()
        ->and($settings->toggle(telegramSettingsCallback($second, 1201))?->enabled)->toBeTrue();
});

test('failed callback edit sends the current settings once to the authorized topic context', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => -1301,
        'thread_id' => 31,
        'enabled' => false,
    ]);
    $editFailure = new FailResult(
        new SendChatAction(-1301, 'typing'),
        new ApiResponse(400, '{}'),
        'Bad Request: message to edit not found',
        errorCode: 400,
    );
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('updateMessage')->once()->andReturn($editFailure);
    $gateway->shouldReceive('send')
        ->once()
        ->with(
            -1301,
            Mockery::type(InputRichMessage::class),
            31,
            Mockery::type(InlineKeyboardMarkup::class),
        )
        ->andReturn(telegramMessage(-1301));

    $changed = telegramSettings(telegramAdministrator(1301), 1, $gateway)
        ->toggle(telegramSettingsCallback($subscription, 1301));

    expect($changed?->enabled)->toBeTrue()
        ->and($subscription->refresh()->enabled)->toBeTrue();
});

test('failed replacement does not loop or repeat the callback mutation', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => -1401,
        'enabled' => false,
    ]);
    $editFailure = new FailResult(
        new SendChatAction(-1401, 'typing'),
        new ApiResponse(400, '{}'),
        'Bad Request: message to edit not found',
        errorCode: 400,
    );
    $sendFailure = new FailResult(
        new SendChatAction(-1401, 'typing'),
        new ApiResponse(429, '{}'),
        'Too Many Requests',
        errorCode: 429,
    );
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('updateMessage')->once()->andReturn($editFailure);
    $gateway->shouldReceive('send')->once()->andReturn($sendFailure);

    $changed = telegramSettings(telegramAdministrator(1401), 1, $gateway)
        ->toggle(telegramSettingsCallback($subscription, 1401));

    // A repeated toggle would restore false, so this also verifies one mutation.
    expect($changed?->enabled)->toBeTrue()
        ->and($subscription->refresh()->enabled)->toBeTrue();
});

test('message not modified is a successful callback no-op without fallback', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $subscription = TelegramNotification::query()->create(['instance_id' => -1501]);
    $notModified = new FailResult(
        new SendChatAction(-1501, 'typing'),
        new ApiResponse(400, '{}'),
        'Bad Request: message is not modified: specified new message content is unchanged',
        errorCode: 400,
    );
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('updateMessage')->once()->andReturn($notModified);
    $gateway->shouldNotReceive('send');

    $changed = telegramSettings(telegramAdministrator(1501), 1, $gateway)
        ->toggle(telegramSettingsCallback($subscription, 1501));

    expect($changed?->enabled)->toBeTrue();
});

test('unauthorized callback remains a no-op without edit fallback', function () {
    config()->set('telegram_notifications.inbound_rate_limit.cache_store', 'array');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => -1601,
        'enabled' => false,
    ]);
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldNotReceive('updateMessage');
    $gateway->shouldNotReceive('send');

    $changed = telegramSettings(
        new ChatMemberMember(telegramUser(1601)),
        gateway: $gateway,
    )->toggle(telegramSettingsCallback($subscription, 1601));

    expect($changed)->toBeNull()
        ->and($subscription->refresh()->enabled)->toBeFalse();
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

    expect($enabled->last_sent_at)->toBeNull()
        ->and($enabled->next_send_at?->equalTo(CarbonImmutable::parse('2026-07-22 10:00:00', 'UTC')))->toBeTrue()
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

test('due query uses only the indexed absolute UTC schedule', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create([
        'instance_id' => 1, 'enabled' => true, 'send_time' => '12:30:00', 'next_send_at' => $now,
    ]);
    TelegramNotification::query()->create([
        'instance_id' => 2, 'enabled' => true, 'send_time' => '12:31:00', 'next_send_at' => $now->addMinute(),
    ]);
    TelegramNotification::query()->create([
        'instance_id' => 3, 'enabled' => true, 'send_time' => '00:00:00',
        'last_sent_at' => $now, 'next_send_at' => $now->addDay()->startOfDay(),
    ]);

    $subscriptions = new TelegramNotificationSubscriptions;

    $querySql = TelegramNotification::query()->dueAt($now)->toSql();

    expect($querySql)->toContain('next_send_at')->not->toContain('send_time')
        ->and($subscriptions->due($now)->pluck('instance_id')->all())->toBe([1])
        ->and($subscriptions->due($now->addMinute())->pluck('instance_id')->all())->toBe([1, 2])
        ->and($subscriptions->due($now->addDay())->pluck('instance_id')->all())->toBe([1, 2, 3]);
});

test('dispatcher builds once continues failures marks only successes and passes thread id', function () {
    config()->set('telegram_notifications.rate_limit.cache_store', 'array');
    config()->set('telegram_notifications.rate_limit.global_per_second', 1000);
    config()->set('telegram_notifications.rate_limit.same_chat_interval_ms', 0);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    $subscriptions = new Collection([
        TelegramNotification::query()->create(['instance_id' => 1, 'thread_id' => 101, 'enabled' => true, 'next_send_at' => $now]),
        TelegramNotification::query()->create(['instance_id' => 2, 'thread_id' => 202, 'enabled' => true, 'next_send_at' => $now]),
        TelegramNotification::query()->create(['instance_id' => 3, 'thread_id' => 303, 'enabled' => true, 'next_send_at' => $now]),
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
    $digest = new InputRichMessage(blocks: []);
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn($digest);
    CarbonImmutable::setTestNow($now);
    $repository = new TelegramNotificationSubscriptions;
    $sender = new TelegramNotificationSender($gateway);
    app()->instance(TelegramNotificationSubscriptions::class, $repository);
    app()->instance(AnalyticsDigestBuilder::class, $builder);
    app()->instance(TelegramNotificationSender::class, $sender);
    $dispatcher = new TelegramNotificationDispatcher($repository, $builder, $sender);

    expect($dispatcher->dispatch($now))->toBe(3)
        ->and($subscriptions[0]->refresh()->last_sent_at?->equalTo($now))->toBeTrue()
        ->and($subscriptions[0]->next_send_at?->equalTo($now->addDay()->startOfDay()))->toBeTrue()
        ->and($subscriptions[1]->refresh()->last_sent_at)->toBeNull()
        ->and($subscriptions[1]->next_send_at?->equalTo($now))->toBeTrue()
        ->and($subscriptions[2]->refresh()->last_sent_at)->toBeNull()
        ->and(TelegramNotificationDelivery::query()->where('status', TelegramNotificationDelivery::STATUS_SENT)->count())->toBe(1)
        ->and(TelegramNotificationDelivery::query()->where('status', TelegramNotificationDelivery::STATUS_FAILED)->count())->toBe(2)
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
        'next_send_at' => $oldRun->startOfDay(),
    ]);
    $digest = new InputRichMessage(blocks: []);
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn($digest);
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
    $repository = new TelegramNotificationSubscriptions;
    $sender = new TelegramNotificationSender($gateway);
    app()->instance(TelegramNotificationSubscriptions::class, $repository);
    app()->instance(AnalyticsDigestBuilder::class, $builder);
    app()->instance(TelegramNotificationSender::class, $sender);
    $dispatcher = new TelegramNotificationDispatcher($repository, $builder, $sender);
    CarbonImmutable::setTestNow($newRun);

    expect($dispatcher->dispatch($newRun))->toBe(1)
        ->and($dispatcher->dispatch($oldRun))->toBe(0)
        ->and($gateway->calls)->toBe(1)
        ->and($subscription->refresh()->last_sent_at?->equalTo($newRun))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('scheduler claims and queues only the configured batch size', function () {
    Queue::fake();
    config()->set('telegram_notifications.delivery.batch_size', 2);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');

    foreach (range(1, 5) as $instanceId) {
        TelegramNotification::query()->create([
            'instance_id' => $instanceId,
            'enabled' => true,
            'next_send_at' => $now,
        ]);
    }

    $dispatcher = new TelegramNotificationDispatcher(
        new TelegramNotificationSubscriptions,
        Mockery::mock(AnalyticsDigestBuilder::class),
        Mockery::mock(TelegramNotificationSender::class),
    );

    expect($dispatcher->dispatch($now))->toBe(2)
        ->and(TelegramNotificationDelivery::query()->count())->toBe(2)
        ->and($dispatcher->dispatch($now))->toBe(2)
        ->and(TelegramNotificationDelivery::query()->count())->toBe(4);

    Queue::assertPushed(SendTelegramNotificationBatch::class, 2);
    Queue::assertPushed(function (SendTelegramNotificationBatch $job): bool {
        return count($job->deliveryIds) === 2
            && $job->tries === 3
            && $job->timeout === SendTelegramNotificationBatch::TIMEOUT
            && $job->backoff === [10, 30];
    });
});

test('conditional occurrence claims exclude concurrent dispatchers and recover after ttl', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create([
        'instance_id' => 1,
        'enabled' => true,
        'next_send_at' => $now,
    ]);
    $subscriptions = new TelegramNotificationSubscriptions;

    $first = $subscriptions->claimDue($now, 1, 30);
    $second = $subscriptions->claimDue($now, 1, 30);
    $beforeExpiry = $subscriptions->claimDue($now->addSeconds(29), 1, 30);
    $recovered = $subscriptions->claimDue($now->addSeconds(30), 1, 30);

    expect($first)->toHaveCount(1)
        ->and($second)->toBeEmpty()
        ->and($beforeExpiry)->toBeEmpty()
        ->and($recovered)->toHaveCount(1)
        ->and($recovered->first()->id)->toBe($first->first()->id)
        ->and($recovered->first()->claim_token)->not->toBe($first->first()->claim_token)
        ->and($recovered->first()->attempt_count)->toBe(0)
        ->and(TelegramNotificationDelivery::query()->count())->toBe(1);
});

test('a successful occurrence ledger is never reclaimed as stale', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create([
        'instance_id' => 1,
        'enabled' => true,
        'next_send_at' => $now,
    ]);
    $subscriptions = new TelegramNotificationSubscriptions;
    $claim = $subscriptions->claimDue($now, 1, 30)->firstOrFail();
    $subscriptions->renewClaims([$claim->id], (string) $claim->claim_token, $now, 30);

    expect($subscriptions->complete($claim->id, (string) $claim->claim_token, $now))->toBeTrue()
        ->and($subscriptions->complete($claim->id, (string) $claim->claim_token, $now->addHour()))->toBeFalse()
        ->and($subscriptions->claimDue($now->addMinute(), 1, 30))->toBeEmpty()
        ->and($claim->refresh()->status)->toBe(TelegramNotificationDelivery::STATUS_SENT)
        ->and($claim->notification->refresh()->next_send_at?->equalTo($now->addDay()->startOfDay()))->toBeTrue()
        ->and(TelegramNotificationDelivery::query()->count())->toBe(1);
});

test('429 retry_after persists a non blocking retry and publishes shared cooldown', function () {
    config()->set('telegram_notifications.rate_limit.cache_store', 'array');
    config()->set('telegram_notifications.delivery.max_attempts', 5);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => 41, 'enabled' => true, 'next_send_at' => $now,
    ]);
    $gateway = new class implements TelegramRichMessageGateway
    {
        public function send(int $chatId, InputRichMessage $message, ?int $messageThreadId = null, ?InlineKeyboardMarkup $keyboard = null): FailResult|Message
        {
            return new FailResult(
                new SendChatAction($chatId, 'typing'),
                new ApiResponse(429, '{}'),
                'Too Many Requests',
                new ResponseParameters(retryAfter: 120),
                429,
            );
        }

        public function updateMessage(InputRichMessage $message, ?InlineKeyboardMarkup $keyboard = null): FailResult|Message|true
        {
            return true;
        }
    };
    $sleeper = Mockery::mock(Sleeper::class);
    $sleeper->shouldReceive('milliseconds')->once()->with(Mockery::on(fn (int $wait): bool => $wait >= 119_000));
    $limiter = new TelegramRateLimiter($sleeper);
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn(new InputRichMessage(blocks: []));
    $repository = new TelegramNotificationSubscriptions;
    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway, $limiter),
    );
    app()->instance(TelegramNotificationDispatcher::class, $dispatcher);
    CarbonImmutable::setTestNow($now);

    expect($dispatcher->dispatch($now))->toBe(1);
    $delivery = TelegramNotificationDelivery::query()->sole();
    expect($delivery->status)->toBe(TelegramNotificationDelivery::STATUS_FAILED)
        ->and($delivery->next_attempt_at->equalTo($now->addSeconds(120)))->toBeTrue()
        ->and($subscription->refresh()->next_send_at?->equalTo($now))->toBeTrue();
    $limiter->reserve(999);

    CarbonImmutable::setTestNow();
});

test('transient exceptions use configured capped backoff without logging exception data', function () {
    config()->set('telegram_notifications.delivery.backoff_seconds', [17]);
    config()->set('telegram_notifications.delivery.max_backoff_seconds', 10);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create(['instance_id' => 42, 'enabled' => true, 'next_send_at' => $now]);
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('send')->once()->andThrow(new RuntimeException('secret-token-in-message'));
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn(new InputRichMessage(blocks: []));
    $repository = new TelegramNotificationSubscriptions;
    CarbonImmutable::setTestNow($now);

    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway),
    );
    app()->instance(TelegramNotificationDispatcher::class, $dispatcher);
    $dispatcher->dispatch($now);

    $delivery = TelegramNotificationDelivery::query()->sole();
    expect($delivery->status)->toBe(TelegramNotificationDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('transient_failure')
        ->and($delivery->next_attempt_at->equalTo($now->addSeconds(10)))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('permanent Telegram 4xx failure is terminal and disables subscription', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => 43, 'enabled' => true, 'next_send_at' => $now,
    ]);
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('send')->once()->andReturn(new FailResult(
        new SendChatAction(43, 'typing'), new ApiResponse(403, '{}'), 'Forbidden', errorCode: 403,
    ));
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn(new InputRichMessage(blocks: []));
    $repository = new TelegramNotificationSubscriptions;
    CarbonImmutable::setTestNow($now);

    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway),
    );
    app()->instance(TelegramNotificationDispatcher::class, $dispatcher);
    $dispatcher->dispatch($now);

    expect(TelegramNotificationDelivery::query()->sole()->status)->toBe(TelegramNotificationDelivery::STATUS_PERMANENT)
        ->and($subscription->refresh()->enabled)->toBeFalse()
        ->and($subscription->next_send_at)->toBeNull()
        ->and($repository->claimDue($now->addMinute(), 1, 30))->toBeEmpty();

    CarbonImmutable::setTestNow();
});

test('transient retry exhaustion is terminal for the occurrence', function () {
    config()->set('telegram_notifications.delivery.max_attempts', 1);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => 44, 'enabled' => true, 'next_send_at' => $now,
    ]);
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('send')->once()->andThrow(new RuntimeException('network down'));
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('build')->once()->andReturn(new InputRichMessage(blocks: []));
    $repository = new TelegramNotificationSubscriptions;
    CarbonImmutable::setTestNow($now);

    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway),
    );
    app()->instance(TelegramNotificationDispatcher::class, $dispatcher);
    $dispatcher->dispatch($now);

    $delivery = TelegramNotificationDelivery::query()->sole();
    expect($delivery->status)->toBe(TelegramNotificationDelivery::STATUS_EXHAUSTED)
        ->and($delivery->last_error)->toBe('attempts_exhausted')
        ->and($subscription->refresh()->enabled)->toBeTrue()
        ->and($subscription->next_send_at?->equalTo($now->addDay()->startOfDay()))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('an expired final claim is represented as exhausted instead of reclaimed', function () {
    config()->set('telegram_notifications.delivery.max_attempts', 1);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create(['instance_id' => 45, 'enabled' => true, 'next_send_at' => $now]);
    $repository = new TelegramNotificationSubscriptions;
    $claim = $repository->claimDue($now, 1, 30)->firstOrFail();
    $repository->renewClaims([$claim->id], (string) $claim->claim_token, $now, 30);

    expect($repository->claimDue($now->addSeconds(30), 1, 30))->toBeEmpty()
        ->and($claim->refresh()->status)->toBe(TelegramNotificationDelivery::STATUS_EXHAUSTED)
        ->and($claim->last_error)->toBe('attempts_exhausted');
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
        ->expectsOutput('Queued 0 Telegram notification(s).')
        ->assertSuccessful();

    $this->artisan('schedule:list')
        ->expectsOutputToContain('telegram:notifications:send')
        ->assertSuccessful();
});
