<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use Phptg\BotApi\FailResult;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\Message;

interface TelegramRichMessageGateway
{
    public function send(
        int $chatId,
        InputRichMessage $message,
        ?int $messageThreadId = null,
        ?InlineKeyboardMarkup $keyboard = null,
    ): FailResult|Message;

    public function updateMessage(
        InputRichMessage $message,
        ?InlineKeyboardMarkup $keyboard = null,
    ): FailResult|Message|true;
}
