<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichMessage;

final readonly class SettingsView
{
    public function __construct(
        public InputRichMessage $message,
        public InlineKeyboardMarkup $keyboard,
    ) {}
}
