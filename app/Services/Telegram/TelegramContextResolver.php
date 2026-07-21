<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramContext;
use InvalidArgumentException;
use Phptg\BotApi\Type\InaccessibleMessage;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;

class TelegramContextResolver
{
    public function resolve(Update $update): TelegramContext
    {
        $message = $update->message ?? $update->channelPost ?? $update->callbackQuery?->message;

        if ($message instanceof InaccessibleMessage) {
            return new TelegramContext($message->chat->id, null, false);
        }

        if (! $message instanceof Message) {
            throw new InvalidArgumentException('Telegram update has no accessible message/chat context.');
        }

        return new TelegramContext($message->chat->id, $message->messageThreadId);
    }
}
