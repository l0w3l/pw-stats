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

        $result = $telegramBotApi->editMessageText(
            messageId: $messageOrigin->messageId,
            chatId: $messageOrigin->chat->id,
            replyMarkup: $keyboard,
            richMessage: $message,
        );

        // Telegram reports an idempotent edit as a 400 even though the desired
        // message is already present. Normalize that harmless outcome to the
        // interface's explicit successful no-op value.
        if ($result instanceof FailResult
            && $result->errorCode === 400
            && str_contains(strtolower($result->description ?? ''), 'message is not modified')) {
            return true;
        }

        return $result;
    }
}
