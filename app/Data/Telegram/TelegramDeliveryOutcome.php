<?php

declare(strict_types=1);

namespace App\Data\Telegram;

readonly class TelegramDeliveryOutcome
{
    private function __construct(
        public TelegramDeliveryOutcomeType $type,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function success(): self
    {
        return new self(TelegramDeliveryOutcomeType::Success);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(TelegramDeliveryOutcomeType::RateLimited, max(1, $retryAfterSeconds));
    }

    public static function transientFailure(): self
    {
        return new self(TelegramDeliveryOutcomeType::TransientFailure);
    }

    public static function permanentFailure(): self
    {
        return new self(TelegramDeliveryOutcomeType::PermanentFailure);
    }
}
