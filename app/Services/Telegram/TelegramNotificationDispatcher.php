<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramDeliveryOutcomeType;
use App\Jobs\SendTelegramNotificationBatch;
use App\Models\TelegramNotificationDelivery;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use Carbon\CarbonImmutable;
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

        // InputFile payloads stay inside this worker; no digest is serialized
        // through the queue and one digest is shared by only this bounded job.
        $digest = $this->digestBuilder->build();
        $sent = 0;

        foreach ($sendable as $delivery) {
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

    private function claimTtlSeconds(): int
    {
        return max(
            (int) config('telegram_notifications.delivery.claim_ttl_seconds', 180),
            SendTelegramNotificationBatch::TIMEOUT + 30,
        );
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
