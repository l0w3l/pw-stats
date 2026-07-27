<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramDeliveryOutcomeType;
use App\Jobs\SendTelegramNotificationBatch;
use App\Models\TelegramNotificationDelivery;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TelegramNotificationDispatcher
{
    public function __construct(
        private readonly TelegramNotificationSubscriptions $subscriptions,
        private readonly AnalyticsDigestBuilder $digestBuilder,
        private readonly TelegramNotificationSender $sender,
    ) {}

    public function dispatch(CarbonImmutable $now): int
    {
        $claims = $this->subscriptions->claimDue(
            $now->utc(),
            max(1, (int) config('telegram_notifications.delivery.batch_size', 25)),
            $this->claimTtlSeconds(),
        );

        if ($claims->isEmpty()) {
            return 0;
        }

        $claimToken = $claims->firstOrFail()->claim_token;
        if ($claimToken === null) {
            return 0;
        }

        SendTelegramNotificationBatch::dispatch($claims->modelKeys(), $claimToken);

        return $claims->count();
    }

    /** @param list<int> $deliveryIds */
    public function deliver(array $deliveryIds, string $claimToken): int
    {
        $now = CarbonImmutable::now('UTC');
        $claims = $this->subscriptions->renewClaims(
            $deliveryIds,
            $claimToken,
            $now,
            $this->claimTtlSeconds(),
        );

        if ($claims->isEmpty()) {
            return 0;
        }

        $sendable = $claims->filter(function (TelegramNotificationDelivery $delivery): bool {
            $notification = $delivery->notification;

            return $notification->enabled
                && $notification->next_send_at?->equalTo($delivery->scheduled_for) === true
                && ($notification->last_sent_at === null
                    || $notification->last_sent_at->lessThan($delivery->scheduled_for));
        });

        foreach ($claims->diff($sendable) as $delivery) {
            if ($delivery->notification->last_sent_at?->greaterThanOrEqualTo($delivery->scheduled_for)) {
                $this->subscriptions->complete(
                    $delivery->id,
                    $claimToken,
                    $delivery->notification->last_sent_at,
                );
            } else {
                $this->subscriptions->release($delivery->id, $claimToken, $now);
            }
        }

        if ($sendable->isEmpty()) {
            return 0;
        }

        try {
            // Locale-independent period data is loaded once while InputFile
            // payloads remain inside this bounded worker.
            $digestData = $this->digestBuilder->prepare();
        } catch (\Throwable) {
            $this->failDigestPreparation($sendable, $claimToken, $now);

            return 0;
        }

        $digests = [];
        $deliveriesByLocale = $sendable->groupBy(
            fn (TelegramNotificationDelivery $delivery): string => $this->normalizedLocale(
                $delivery->notification->locale,
            ),
        );

        foreach ($deliveriesByLocale as $locale => $localeDeliveries) {
            try {
                $digests[$locale] = $this->digestBuilder->build($locale, $digestData);
            } catch (\Throwable) {
                $this->failDigestPreparation($localeDeliveries, $claimToken, $now, $locale);
            }
        }

        $sent = 0;

        foreach ($sendable as $delivery) {
            $locale = $this->normalizedLocale($delivery->notification->locale);
            $digest = $digests[$locale] ?? null;

            if ($digest === null) {
                continue;
            }

            $outcome = $this->sender->deliver($delivery->id, $delivery->notification, $digest);
            $outcomeAt = CarbonImmutable::now('UTC');

            if ($outcome->type === TelegramDeliveryOutcomeType::Success) {
                try {
                    if ($this->subscriptions->complete($delivery->id, $claimToken, $outcomeAt)) {
                        $sent++;
                    } else {
                        Log::critical('Telegram send succeeded but its durable outcome was not recorded.', [
                            'delivery_id' => $delivery->id,
                        ]);
                    }
                } catch (\Throwable) {
                    // Telegram accepted the message, but the local commit is
                    // uncertain. Keep the claim until its TTL rather than
                    // immediately duplicating the send via a queue retry.
                    Log::critical('Telegram send succeeded but its durable outcome commit was ambiguous.', [
                        'delivery_id' => $delivery->id,
                    ]);
                }

                continue;
            }

            if ($outcome->type === TelegramDeliveryOutcomeType::PermanentFailure) {
                $this->subscriptions->terminate(
                    $delivery->id,
                    $claimToken,
                    $outcomeAt,
                    TelegramNotificationDelivery::STATUS_PERMANENT,
                    'permanent_telegram_4xx',
                    config('telegram_notifications.delivery.permanent_failure_policy', 'disable') === 'disable',
                );

                continue;
            }

            if ($delivery->attempt_count >= $this->maxAttempts()) {
                $this->subscriptions->terminate(
                    $delivery->id,
                    $claimToken,
                    $outcomeAt,
                    TelegramNotificationDelivery::STATUS_EXHAUSTED,
                    'attempts_exhausted',
                    false,
                );
                Log::error('Telegram notification delivery exhausted its retry budget.', [
                    'delivery_id' => $delivery->id,
                ]);

                continue;
            }

            $delay = $outcome->type === TelegramDeliveryOutcomeType::RateLimited
                ? max(1, (int) $outcome->retryAfterSeconds)
                : $this->backoffSeconds($delivery->attempt_count);
            $this->subscriptions->retry(
                $delivery->id,
                $claimToken,
                $outcomeAt,
                $outcomeAt->addSeconds($delay),
                $outcome->type->value,
            );
        }

        return $sent;
    }

    /** @param Collection<int, TelegramNotificationDelivery> $deliveries */
    private function failDigestPreparation(
        Collection $deliveries,
        string $claimToken,
        CarbonImmutable $failedAt,
        ?string $locale = null,
    ): void {
        foreach ($deliveries as $delivery) {
            $transitioned = $delivery->attempt_count >= $this->maxAttempts()
                ? $this->subscriptions->terminate(
                    $delivery->id,
                    $claimToken,
                    $failedAt,
                    TelegramNotificationDelivery::STATUS_EXHAUSTED,
                    'attempts_exhausted',
                    false,
                )
                : $this->subscriptions->retry(
                    $delivery->id,
                    $claimToken,
                    $failedAt,
                    $failedAt->addSeconds($this->backoffSeconds($delivery->attempt_count)),
                    'digest_preparation_failed',
                );

            if (! $transitioned) {
                Log::critical('Telegram digest preparation failure was not durably recorded.', [
                    'delivery_id' => $delivery->id,
                ]);
            }
        }

        Log::error('Telegram digest preparation failed; durable delivery outcomes were recorded.', array_filter([
            'delivery_count' => $deliveries->count(),
            'locale' => $locale,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function claimTtlSeconds(): int
    {
        return max(
            (int) config('telegram_notifications.delivery.claim_ttl_seconds', 180),
            SendTelegramNotificationBatch::TIMEOUT + 30,
        );
    }

    private function normalizedLocale(?string $locale): string
    {
        return $locale === 'en' ? 'en' : 'ru';
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('telegram_notifications.delivery.max_attempts', 5));
    }

    private function backoffSeconds(int $attempt): int
    {
        $configured = config('telegram_notifications.delivery.backoff_seconds', [60, 300, 900, 3600]);
        $backoffs = is_array($configured)
            ? array_values(array_map(static fn (mixed $value): int => max(1, (int) $value), $configured))
            : [60];
        $delay = $backoffs[min(max(0, $attempt - 1), count($backoffs) - 1)] ?? 60;

        return min($delay, max(1, (int) config('telegram_notifications.delivery.max_backoff_seconds', 3600)));
    }
}
