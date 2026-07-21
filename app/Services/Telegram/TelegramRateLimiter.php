<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\Sleeper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TelegramRateLimiter
{
    public function __construct(private readonly Sleeper $sleeper) {}

    public function reserve(int $chatId): int
    {
        $store = Cache::store((string) config('telegram_notifications.rate_limit.cache_store'));
        $lock = $store->lock('telegram-notifications:rate-limit:reservation', 10);

        try {
            /** @var int $wait */
            $wait = $lock->block(5, function () use ($store, $chatId): int {
                $now = (int) floor(microtime(true) * 1000);
                $globalKey = 'telegram-notifications:rate-limit:global-next';
                $chatKey = 'telegram-notifications:rate-limit:chat-next:'.$chatId;
                $next = max($now, (int) $store->get($globalKey, 0), (int) $store->get($chatKey, 0));
                $globalInterval = (int) ceil(1000 / max(1, (int) config('telegram_notifications.rate_limit.global_per_second')));
                $chatInterval = max(0, (int) config('telegram_notifications.rate_limit.same_chat_interval_ms'));

                $store->put($globalKey, $next + $globalInterval, 3600);
                $store->put($chatKey, $next + $chatInterval, 3600);

                return max(0, $next - $now);
            });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Unable to reserve a Telegram API rate-limit slot.', previous: $exception);
        }

        $this->sleeper->milliseconds($wait);

        return $wait;
    }
}
