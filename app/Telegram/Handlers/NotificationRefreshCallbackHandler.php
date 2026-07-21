<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Services\Telegram\TelegramSettings;
use Phptg\BotApi\Type\Update\Update;

class NotificationRefreshCallbackHandler
{
    public function handle(Update $update, TelegramSettings $settings): void
    {
        $settings->update($update);
    }
}
