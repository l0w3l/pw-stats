<?php

declare(strict_types=1);

namespace App\Data\Telegram;

enum TelegramDeliveryOutcomeType: string
{
    case Success = 'success';
    case RateLimited = 'rate_limited';
    case TransientFailure = 'transient_failure';
    case PermanentFailure = 'permanent_failure';
}
