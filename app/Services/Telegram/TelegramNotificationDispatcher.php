<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Telegram\Messages\AnalyticsDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class TelegramNotificationDispatcher
{
    public function __construct(
        private readonly TelegramNotificationSubscriptions $subscriptions,
        private readonly AnalyticsDigestBuilder $digestBuilder,
        private readonly TelegramNotificationSender $sender,
    ) {}

    public function dispatch(CarbonImmutable $now): int
    {
        $due = $this->subscriptions->due($now->utc());

        if ($due->isEmpty()) {
            return 0;
        }

        $digest = $this->digestBuilder->build();
        $sent = 0;
        $cache = Cache::store((string) config('telegram_notifications.rate_limit.cache_store'));

        foreach ($due as $subscription) {
            $lock = $cache->lock(
                "telegram-notification:delivery:{$subscription->id}",
                300,
            );

            if (! $lock->get()) {
                continue;
            }

            try {
                $subscription->refresh();
                if (
                    ! $subscription->enabled
                    || $subscription->last_sent_at?->greaterThanOrEqualTo($now->utc()->startOfDay())
                ) {
                    continue;
                }

                $sent += $this->sender->send($subscription, $digest) ? 1 : 0;
            } finally {
                $lock->release();
            }
        }

        return $sent;
    }
}
