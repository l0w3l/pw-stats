<?php

use App\Contracts\Telegram\Sleeper;
use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Data\PixelWorld\Analytics\AnalyticsDigestData;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PointsThresholdData;
use App\Data\Telegram\TelegramContext;
use App\Jobs\SendTelegramNotificationBatch;
use App\Models\TelegramNotification;
use App\Models\TelegramNotificationDelivery;
use App\Queries\CurrentPointsThresholdAnalytics;
use App\Queries\PeriodPlayerCountTrends;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
use App\Services\Telegram\TelegramNotificationDispatcher;
use App\Services\Telegram\TelegramNotificationSender;
use App\Services\Telegram\TelegramNotificationSubscriptions;
use App\Services\Telegram\TelegramRateLimiter;
use App\Telegram\Messages\AnalyticsChartMediaFactory;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Phptg\BotApi\Type\Chat;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\Message;
use Predis\Client;
use Tests\Support\TelegramNotificationFixtures;

uses(RefreshDatabase::class);

function scalingTelegramMessage(int $chatId): Message
{
    return new Message(1, new DateTimeImmutable('@0'), new Chat($chatId, 'private'));
}

function postgresConcurrencyUrl(): ?string
{
    $url = getenv('TELEGRAM_TEST_POSTGRES_URL');

    return is_string($url) && $url !== '' ? $url : null;
}

function scalingDigestData(): AnalyticsDigestData
{
    return new AnalyticsDigestData(
        new LeaderboardAnalyticsData(
            playerCountTrends: [new PlayerCountTrendData('day', 100, 90, 10)],
            pointsThresholds: [],
            mostActivePlayers: [],
            momentumPlayers: [],
        ),
    );
}

test('duplicate workers cannot claim or deliver one occurrence twice', function () {
    // Arrange: one due occurrence and a fully mocked Telegram boundary.
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    TelegramNotification::query()->create([
        'instance_id' => 501,
        'enabled' => true,
        'next_send_at' => $now,
    ]);
    $repository = new TelegramNotificationSubscriptions;
    $firstClaim = $repository->claimDue($now, 1, 30)->firstOrFail();
    $secondClaim = $repository->claimDue($now, 1, 30);
    $gateway = Mockery::mock(TelegramRichMessageGateway::class);
    $gateway->shouldReceive('send')->once()->andReturn(scalingTelegramMessage(501));
    $builder = Mockery::mock(AnalyticsDigestBuilder::class);
    $builder->shouldReceive('prepare')->once()->andReturn(scalingDigestData());
    $builder->shouldReceive('build')->once()->andReturn(new InputRichMessage(blocks: []));
    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway),
    );

    // Act: a foreign worker, the owner, and then a duplicate owner job run.
    $foreignResult = $dispatcher->deliver([$firstClaim->id], 'not-the-owner');
    $ownerResult = $dispatcher->deliver([$firstClaim->id], (string) $firstClaim->claim_token);
    $duplicateResult = $dispatcher->deliver([$firstClaim->id], (string) $firstClaim->claim_token);

    // Assert: only the owner sends, and durable completion closes the occurrence.
    expect($secondClaim)->toBeEmpty()
        ->and($foreignResult)->toBe(0)
        ->and($ownerResult)->toBe(1)
        ->and($duplicateResult)->toBe(0)
        ->and($firstClaim->refresh()->status)->toBe(TelegramNotificationDelivery::STATUS_SENT)
        ->and(TelegramNotificationDelivery::query()->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('only one duplicate queue worker can transition a claim to processing', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create([
        'instance_id' => 503,
        'enabled' => true,
        'next_send_at' => $now,
    ]);
    $repository = new TelegramNotificationSubscriptions;
    $claim = $repository->claimDue($now, 1, 30)->firstOrFail();
    $ids = [$claim->id];
    $token = (string) $claim->claim_token;

    $firstWorker = $repository->renewClaims($ids, $token, $now, 30);
    $duplicateWorker = $repository->renewClaims($ids, $token, $now, 30);

    expect($firstWorker)->toHaveCount(1)
        ->and($duplicateWorker)->toBeEmpty()
        ->and($claim->refresh()->status)->toBe(TelegramNotificationDelivery::STATUS_PROCESSING)
        ->and($claim->attempt_count)->toBe(1);
});

test('large due sets advance in stable bounded batches', function () {
    // Arrange: more than three batches, without retaining fixture models.
    Queue::fake();
    $batchSize = 25;
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    config()->set('telegram_notifications.delivery.batch_size', $batchSize);
    TelegramNotificationFixtures::dueSubscriptions(($batchSize * 3) + 2, $now);
    $dispatcher = new TelegramNotificationDispatcher(
        new TelegramNotificationSubscriptions,
        Mockery::mock(AnalyticsDigestBuilder::class),
        Mockery::mock(TelegramNotificationSender::class),
    );
    $counts = [];
    $queryCounts = [];
    $memoryGrowth = [];

    // Act: inspect each full increment independently so query logging itself is bounded.
    foreach (range(1, 3) as $unused) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        gc_collect_cycles();
        $before = memory_get_usage(true);
        $counts[] = $dispatcher->dispatch($now);
        $memoryGrowth[] = max(0, memory_get_usage(true) - $before);
        $queryCounts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $tail = $dispatcher->dispatch($now);
    $empty = $dispatcher->dispatch($now);
    $payloadSizes = Queue::pushed(SendTelegramNotificationBatch::class)
        ->map(static fn (SendTelegramNotificationBatch $job): int => count($job->deliveryIds))
        ->all();

    // Assert: work, queue payloads, query count, and retained allocation do not grow with backlog.
    expect($counts)->toBe([$batchSize, $batchSize, $batchSize])
        ->and($tail)->toBe(2)
        ->and($empty)->toBe(0)
        ->and($payloadSizes)->toBe([$batchSize, $batchSize, $batchSize, 2])
        ->and(max($queryCounts) - min($queryCounts))->toBeLessThanOrEqual(2)
        ->and(max($queryCounts))->toBeLessThanOrEqual(($batchSize * 4) + 20)
        ->and(max($memoryGrowth))->toBeLessThanOrEqual(16 * 1024 * 1024)
        ->and(TelegramNotificationDelivery::query()->count())->toBe(($batchSize * 3) + 2);
});

test('bounded mixed locale batch loads report data once and shares one chartless digest per locale', function () {
    // Arrange: four recipients span RU, EN, and one unsupported persisted value.
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $subscriptions = collect([
        [901, 'ru'],
        [902, 'en'],
        [903, 'en'],
        [904, 'ru'],
    ])->map(fn (array $values): TelegramNotification => TelegramNotification::query()->create([
        'instance_id' => $values[0],
        'locale' => $values[1],
        'enabled' => true,
        'next_send_at' => $now,
    ]));
    DB::table('telegram_notifications')->where('instance_id', 904)->update(['locale' => 'EN']);

    $trends = Mockery::mock(PeriodPlayerCountTrends::class);
    $trends->shouldReceive('get')->once()->andReturn([
        new PlayerCountTrendData('day', 100, 90, 10),
    ]);
    $thresholds = Mockery::mock(CurrentPointsThresholdAnalytics::class);
    $thresholds->shouldReceive('get')->once()->andReturn([
        new PointsThresholdData('day', 50, 25, 10),
        new PointsThresholdData('week', 100, 50, 20),
        new PointsThresholdData('month', 150, 75, 30),
    ]);
    $charts = Mockery::mock(PlayerCountChartService::class);
    $charts->shouldNotReceive('data', 'generateFromData');
    $chartMedia = Mockery::mock(AnalyticsChartMediaFactory::class);
    $chartMedia->shouldNotReceive('make');
    app()->instance(PeriodPlayerCountTrends::class, $trends);
    app()->instance(CurrentPointsThresholdAnalytics::class, $thresholds);
    app()->instance(PlayerCountChartService::class, $charts);
    app()->instance(AnalyticsChartMediaFactory::class, $chartMedia);
    $builder = app(AnalyticsDigestBuilder::class);
    $gateway = new class implements TelegramRichMessageGateway
    {
        /** @var array<int, InputRichMessage> */
        public array $messages = [];

        public function send(int $chatId, InputRichMessage $message, ?int $messageThreadId = null, ?InlineKeyboardMarkup $keyboard = null): Message
        {
            $this->messages[$chatId] = $message;

            return scalingTelegramMessage($chatId);
        }

        public function updateMessage(InputRichMessage $message, ?InlineKeyboardMarkup $keyboard = null): Message|true
        {
            return true;
        }
    };
    $repository = new TelegramNotificationSubscriptions;
    $claims = $repository->claimDue($now, 4, 30);
    $dispatcher = new TelegramNotificationDispatcher(
        $repository,
        $builder,
        new TelegramNotificationSender($gateway),
    );

    // Act: deliver the complete bounded claim in one worker invocation.
    $sent = $dispatcher->deliver($claims->modelKeys(), (string) $claims->firstOrFail()->claim_token);

    // Assert: recipients share one generated object per locale and never across locales.
    expect($sent)->toBe(4)
        ->and($gateway->messages[901])->toBe($gateway->messages[904])
        ->and($gateway->messages[902])->toBe($gateway->messages[903])
        ->and($gateway->messages[901])->not->toBe($gateway->messages[902])
        ->and($gateway->messages[901]->blocks[0]->text)->toBe('Pixel World · Статистика')
        ->and($gateway->messages[902]->blocks[0]->text)->toBe('Pixel World · Statistics')
        ->and($subscriptions->map->refresh()->every(
            fn (TelegramNotification $subscription): bool => $subscription->last_sent_at?->equalTo($now) === true,
        ))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('stale claims recover but completed occurrences never recover', function () {
    // Arrange: two independently claimed occurrences, one of which completes.
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    foreach ([601, 602] as $instanceId) {
        TelegramNotification::query()->create([
            'instance_id' => $instanceId,
            'enabled' => true,
            'next_send_at' => $now,
        ]);
    }
    $repository = new TelegramNotificationSubscriptions;
    $claims = $repository->claimDue($now, 2, 30);
    $repository->renewClaims($claims->modelKeys(), (string) $claims->firstOrFail()->claim_token, $now, 30);
    $claims = $claims->map->refresh();
    $completed = $claims->firstOrFail();
    $interrupted = $claims->last();
    $repository->complete($completed->id, (string) $completed->claim_token, $now);

    // Act: recovery is attempted immediately before and exactly at expiry.
    $beforeExpiry = $repository->claimDue($now->addSeconds(29), 2, 30);
    $recovered = $repository->claimDue($now->addSeconds(30), 2, 30);

    // Assert: only the interrupted occurrence is recovered under a new token.
    expect($beforeExpiry)->toBeEmpty()
        ->and($recovered)->toHaveCount(1)
        ->and($recovered->first()->id)->toBe($interrupted->id)
        ->and($recovered->first()->claim_token)->not->toBe($interrupted->claim_token)
        ->and($recovered->first()->attempt_count)->toBe(1)
        ->and($completed->refresh()->status)->toBe(TelegramNotificationDelivery::STATUS_SENT)
        ->and($completed->attempt_count)->toBe(1);
});

test('worker interruption preserves completed work and recovers only its unfinished batch tail', function () {
    // Arrange: a worker claims a batch and durably finishes only its first item.
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    foreach ([701, 702, 703] as $instanceId) {
        TelegramNotification::query()->create([
            'instance_id' => $instanceId,
            'enabled' => true,
            'next_send_at' => $now,
        ]);
    }
    $repository = new TelegramNotificationSubscriptions;
    $original = $repository->claimDue($now, 3, 30);
    $repository->renewClaims($original->modelKeys(), (string) $original->firstOrFail()->claim_token, $now, 30);
    $original = $original->map->refresh();
    $finished = $original->firstOrFail();
    $repository->complete($finished->id, (string) $finished->claim_token, $now);

    // Act: simulate process death by doing nothing with the tail until claim expiry.
    $premature = $repository->claimDue($now->addSeconds(29), 3, 30);
    $recovered = $repository->claimDue($now->addSeconds(30), 3, 30);

    // Assert: recovery is delayed and excludes already committed work.
    expect($premature)->toBeEmpty()
        ->and($recovered->modelKeys())->toBe($original->slice(1)->values()->modelKeys())
        ->and($recovered)->toHaveCount(2)
        ->and($finished->refresh()->status)->toBe(TelegramNotificationDelivery::STATUS_SENT)
        ->and(TelegramNotificationDelivery::query()->where('status', TelegramNotificationDelivery::STATUS_CLAIMED)->count())->toBe(2);
});

test('queue delay and repeated stale claims do not consume delivery attempts', function () {
    config()->set('telegram_notifications.delivery.max_attempts', 2);
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    TelegramNotification::query()->create([
        'instance_id' => 704,
        'enabled' => true,
        'next_send_at' => $now,
    ]);
    $repository = new TelegramNotificationSubscriptions;
    $claim = $repository->claimDue($now, 1, 30)->firstOrFail();

    foreach (range(1, 5) as $cycle) {
        $claim = $repository->claimDue($now->addSeconds($cycle * 30), 1, 30)->firstOrFail();
    }

    expect($claim->attempt_count)->toBe(0)
        ->and($claim->status)->toBe(TelegramNotificationDelivery::STATUS_CLAIMED);
});

test('disable and re-enable cannot resurrect a stale scheduled occurrence', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
    $subscription = TelegramNotification::query()->create([
        'instance_id' => 705,
        'enabled' => true,
        'send_time' => '12:30:00',
        'next_send_at' => $now,
    ]);
    $repository = new TelegramNotificationSubscriptions;
    $oldClaim = $repository->claimDue($now, 1, 30)->firstOrFail();
    $context = new TelegramContext($subscription->instance_id, null);

    $repository->toggle($context, $now);
    $repository->enable($context, $now->addMinute());

    expect($repository->claimDue($now->addSeconds(30), 1, 30))->toBeEmpty()
        ->and($oldClaim->refresh()->scheduled_for->equalTo($now))->toBeTrue()
        ->and($subscription->refresh()->next_send_at?->equalTo($now->addDay()))->toBeTrue();
});

test('postgres skip locked arbitrates simultaneous claimers on separate connections', function () {
    // Arrange: this opt-in test uses an isolated schema on an explicitly supplied PostgreSQL URL.
    $url = postgresConcurrencyUrl();
    if ($url === null) {
        $this->markTestSkipped('Set TELEGRAM_TEST_POSTGRES_URL to run PostgreSQL concurrency coverage.');
    }
    if (! extension_loaded('pdo_pgsql') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('PostgreSQL concurrency coverage requires pdo_pgsql and pcntl.');
    }

    $schema = 'telegram_claim_test_'.bin2hex(random_bytes(6));
    $originalDefault = config('database.default');
    $base = config('database.connections.pgsql');
    $base['url'] = $url;
    $admin = $base;
    $admin['search_path'] = 'public';
    $worker = $base;
    $worker['search_path'] = $schema;
    config()->set('database.connections.telegram_pg_admin', $admin);
    config()->set('database.connections.telegram_pg_a', $worker);
    config()->set('database.connections.telegram_pg_b', $worker);

    try {
        DB::purge('telegram_pg_admin');
        DB::connection('telegram_pg_admin')->getPdo();
    } catch (Throwable $exception) {
        $this->markTestSkipped('Configured PostgreSQL service is unavailable: '.$exception->getMessage());
    }

    try {
        DB::purge('telegram_pg_admin');
        DB::connection('telegram_pg_admin')->statement('create schema "'.$schema.'"');
        Schema::connection('telegram_pg_a')->create('telegram_notifications', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('instance_id');
            $table->bigInteger('thread_id')->nullable();
            $table->string('context_key')->unique();
            $table->time('send_time')->default('00:00:00');
            $table->boolean('enabled')->default(false);
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('telegram_pg_a')->create('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_notification_id');
            $table->timestamp('scheduled_for');
            $table->string('status')->default(TelegramNotificationDelivery::STATUS_PENDING);
            $table->timestamp('next_attempt_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['telegram_notification_id', 'scheduled_for']);
        });
        config()->set('database.default', 'telegram_pg_a');
        DB::purge('telegram_pg_a');
        $now = CarbonImmutable::parse('2026-07-21 12:30:00', 'UTC');
        TelegramNotification::query()->create([
            'instance_id' => 801,
            'enabled' => true,
            'next_send_at' => $now,
        ]);
        $startFile = tempnam(sys_get_temp_dir(), 'telegram-claim-start-');
        $resultFiles = [
            tempnam(sys_get_temp_dir(), 'telegram-claim-a-'),
            tempnam(sys_get_temp_dir(), 'telegram-claim-b-'),
        ];
        if ($startFile === false || in_array(false, $resultFiles, true)) {
            throw new RuntimeException('Unable to allocate PostgreSQL concurrency synchronization files.');
        }
        unlink($startFile);
        $pids = [];

        // Act: release two forked workers against distinct Laravel connections together.
        foreach (['telegram_pg_a', 'telegram_pg_b'] as $index => $connection) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork PostgreSQL claim worker.');
            }
            if ($pid === 0) {
                try {
                    $deadline = microtime(true) + 5;
                    while (! file_exists($startFile) && microtime(true) < $deadline) {
                        usleep(1_000);
                    }
                    config()->set('database.default', $connection);
                    DB::purge($connection);
                    $claims = (new TelegramNotificationSubscriptions)->claimDue($now, 1, 30);
                    file_put_contents($resultFiles[$index], json_encode([
                        'count' => $claims->count(),
                        'token' => $claims->first()?->claim_token,
                    ], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($resultFiles[$index], json_encode(['error' => $exception->getMessage()]));
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        touch($startFile);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(
            static fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
            $resultFiles,
        );

        // Assert: exactly one process owns the sole durable occurrence.
        expect(array_sum(array_column($results, 'count')))->toBe(1)
            ->and(array_filter(array_column($results, 'token')))->toHaveCount(1);
        config()->set('database.default', 'telegram_pg_a');
        DB::purge('telegram_pg_a');
        expect(TelegramNotificationDelivery::query()->count())->toBe(1);
    } finally {
        foreach ([$startFile ?? null, ...($resultFiles ?? [])] as $file) {
            if (is_string($file) && file_exists($file)) {
                unlink($file);
            }
        }
        try {
            DB::purge('telegram_pg_admin');
            DB::connection('telegram_pg_admin')->statement('drop schema if exists "'.$schema.'" cascade');
        } catch (Throwable) {
            // The primary assertion reports connection failures; cleanup is best effort.
        }
        config()->set('database.default', $originalDefault);
        DB::purge('telegram_pg_a');
        DB::purge('telegram_pg_b');
    }
});

test('redis lock and cooldown state is shared between limiter instances', function () {
    // Arrange: probe the optional Redis store and isolate all chat-specific state.
    if (! extension_loaded('redis') && ! class_exists(Client::class)) {
        $this->markTestSkipped('Redis client support is not installed.');
    }
    config()->set('telegram_notifications.rate_limit.cache_store', 'redis');
    config()->set('telegram_notifications.rate_limit.global_per_second', 1000);
    config()->set('telegram_notifications.rate_limit.same_chat_interval_ms', 0);
    config()->set('cache.prefix', 'telegram-notification-test:'.bin2hex(random_bytes(8)).':');
    Cache::forgetDriver('redis');
    $probe = 'telegram-notification-test:'.bin2hex(random_bytes(8));
    try {
        $store = Cache::store('redis');
        $store->put($probe, 'ok', 10);
        if ($store->get($probe) !== 'ok') {
            $this->markTestSkipped('Redis cache probe did not round trip.');
        }
    } catch (Throwable $exception) {
        $this->markTestSkipped('Redis service is unavailable: '.$exception->getMessage());
    } finally {
        try {
            if (isset($store)) {
                $store->forget($probe);
            }
        } catch (Throwable) {
        }
    }
    $chatId = random_int(1_000_000, PHP_INT_MAX);
    $globalKey = 'telegram-notifications:rate-limit:global-next';
    $chatKey = 'telegram-notifications:rate-limit:chat-next:'.$chatId;
    $sleeper = new class implements Sleeper
    {
        /** @var list<int> */
        public array $waits = [];

        public function milliseconds(int $milliseconds): void
        {
            $this->waits[] = $milliseconds;
        }
    };
    $first = new TelegramRateLimiter($sleeper);
    $second = new TelegramRateLimiter($sleeper);
    $owner = $store->lock('telegram-notification-test-lock:'.$chatId, 10);
    $contender = $store->lock('telegram-notification-test-lock:'.$chatId, 10);

    try {
        // Act: contend for a distributed lock and publish cooldown through another instance.
        $ownerAcquired = $owner->get();
        $contenderWhileHeld = $contender->get();
        $owner->release();
        $contenderAfterRelease = $contender->get();
        $first->cooldown($chatId, 2);
        $wait = $second->reserve($chatId);

        // Assert: lock exclusion and cross-instance cooldown are both Redis-backed.
        expect($ownerAcquired)->toBeTrue()
            ->and($contenderWhileHeld)->toBeFalse()
            ->and($contenderAfterRelease)->toBeTrue()
            ->and($wait)->toBeGreaterThanOrEqual(1_500)
            ->and($sleeper->waits)->toBe([$wait]);
    } finally {
        $contender->release();
        $store->forget($globalKey);
        $store->forget($chatKey);
    }
});
