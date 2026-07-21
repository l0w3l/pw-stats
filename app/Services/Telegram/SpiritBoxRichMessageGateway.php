<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramRichMessageGateway;
use Lowel\Telepath\Facades\Extrasense;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\InlineKeyboardMarkup;
use Phptg\BotApi\Type\InputRichMessage;
use Phptg\BotApi\Type\Message;

class SpiritBoxRichMessageGateway implements TelegramRichMessageGateway
{
    public function __construct(private readonly TelegramRateLimiter $rateLimiter) {}

    public function send(
        int $chatId,
        InputRichMessage $message,
        ?int $messageThreadId = null,
        ?InlineKeyboardMarkup $keyboard = null,
    ): FailResult|Message {
        $this->rateLimiter->reserve($chatId);

        $telegramBotApi = app(TelegramBotApi::class);

        return $telegramBotApi->sendRichMessage(
            chatId: $chatId,
            messageThreadId: $messageThreadId,
            replyMarkup: $keyboard,
            richMessage: $message,
        );
    }

    public function updateMessage(
        InputRichMessage $message,
        ?InlineKeyboardMarkup $keyboard = null,
    ): FailResult|Message|true {
        $messageOrigin = Extrasense::message();
        $telegramBotApi = app(TelegramBotApi::class);
        $this->rateLimiter->reserve($messageOrigin->chat->id);

        return $telegramBotApi->editMessageText(
            messageId: $messageOrigin->messageId,
            chatId: $messageOrigin->chat->id,
            replyMarkup: $keyboard,
            richMessage: $message,
        );
    }
}
