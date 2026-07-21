<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramRichMessageGateway;
use App\Models\TelegramNotification;
use Illuminate\Support\Facades\Log;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\Type\InputRichMessage;

class TelegramNotificationSender
{
    public function __construct(
        private readonly TelegramRichMessageGateway $gateway,
    ) {}

    public function send(
        TelegramNotification $subscription,
        InputRichMessage $digest,
    ): bool {
        try {
            $result = $this->gateway->send(
                $subscription->instance_id,
                $digest,
                $subscription->thread_id,
            );

            if ($result instanceof FailResult) {
                Log::error('Telegram notification delivery failed.', [
                    'subscription_id' => $subscription->id,
                    'chat_id' => $subscription->instance_id,
                    'error_code' => $result->errorCode,
                    'description' => $result->description,
                    'retry_after' => $result->parameters?->retryAfter,
                ]);

                return false;
            }

            $subscription->forceFill(['last_sent_at' => now('UTC')])->save();

            return true;
        } catch (\Throwable $exception) {
            Log::error('Telegram notification delivery raised an exception.', [
                'subscription_id' => $subscription->id,
                'chat_id' => $subscription->instance_id,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
