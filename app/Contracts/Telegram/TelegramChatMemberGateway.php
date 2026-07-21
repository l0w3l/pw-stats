<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use Phptg\BotApi\Type\ChatMember;

interface TelegramChatMemberGateway
{
    public function get(int $chatId, int $userId): ?ChatMember;
}
