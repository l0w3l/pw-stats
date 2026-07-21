<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;

final readonly class TelegramInboundRateLimiter
{
    public function __construct(private CacheManager $cache) {}

    public function allows(int $actorId, int $chatId): bool
    {
        $configuredStore = config('telegram_notifications.inbound_rate_limit.cache_store');
        $store = is_string($configuredStore) && $configuredStore !== '' ? $configuredStore : null;
        $maxAttempts = max(1, (int) config('telegram_notifications.inbound_rate_limit.max_attempts', 10));
        $decaySeconds = max(1, (int) config('telegram_notifications.inbound_rate_limit.decay_seconds', 60));
        $limiter = new RateLimiter($this->cache->store($store));
        $key = sprintf('telegram:inbound:%d:%d', $chatId, $actorId);

        // Increment first so concurrent workers cannot all pass a check-then-hit race.
        return $limiter->hit($key, $decaySeconds) <= $maxAttempts;
    }
}
