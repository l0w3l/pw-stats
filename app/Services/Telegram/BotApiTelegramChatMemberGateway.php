<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatMemberGateway;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\ChatMember;
use Throwable;

final readonly class BotApiTelegramChatMemberGateway implements TelegramChatMemberGateway
{
    public function __construct(private TelegramBotApi $botApi) {}

    public function get(int $chatId, int $userId): ?ChatMember
    {
        try {
            $member = $this->botApi->getChatMember($chatId, $userId);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $member instanceof FailResult ? null : $member;
    }
}
