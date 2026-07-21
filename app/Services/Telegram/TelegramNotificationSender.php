<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Data\Telegram\TelegramDeliveryOutcome;
use App\Models\TelegramNotification;
use Illuminate\Support\Facades\Log;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\Type\InputRichMessage;

class TelegramNotificationSender
{
    public function __construct(
        private readonly TelegramRichMessageGateway $gateway,
        private readonly ?TelegramRateLimiter $rateLimiter = null,
    ) {}

    public function deliver(
        int $deliveryId,
        TelegramNotification $subscription,
        InputRichMessage $digest,
    ): TelegramDeliveryOutcome {
        try {
            $result = $this->gateway->send(
                $subscription->instance_id,
                $digest,
                $subscription->thread_id,
            );

            if ($result instanceof FailResult) {
                if ($result->errorCode === 429) {
                    $retryAfter = max(
                        1,
                        $result->parameters?->retryAfter
                            ?? (int) config('telegram_notifications.delivery.default_retry_after_seconds', 60),
                    );
                    try {
                        $this->rateLimiter?->cooldown($subscription->instance_id, $retryAfter);
                    } catch (\Throwable) {
                        // Persisting retry_after on the delivery remains the
                        // source of truth if the shared cooldown store is down.
                    }
                    Log::warning('Telegram notification delivery was rate limited.', ['delivery_id' => $deliveryId]);

                    return TelegramDeliveryOutcome::rateLimited($retryAfter);
                }

                if ($result->errorCode !== null && $result->errorCode >= 400 && $result->errorCode < 500
                    && $result->errorCode !== 408) {
                    Log::warning('Telegram notification delivery failed permanently.', ['delivery_id' => $deliveryId]);

                    return TelegramDeliveryOutcome::permanentFailure();
                }

                Log::warning('Telegram notification delivery failed transiently.', ['delivery_id' => $deliveryId]);

                return TelegramDeliveryOutcome::transientFailure();
            }

            return TelegramDeliveryOutcome::success();
        } catch (\Throwable) {
            // Exception messages may contain request URLs or credentials. The
            // durable delivery ID is sufficient to correlate internal traces.
            Log::warning('Telegram notification delivery raised a transient exception.', [
                'delivery_id' => $deliveryId,
            ]);

            return TelegramDeliveryOutcome::transientFailure();
        }
    }
}
