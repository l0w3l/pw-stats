<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatMemberGateway;
use App\Data\Telegram\TelegramContext;
use Phptg\BotApi\Type\ChatMemberAdministrator;
use Phptg\BotApi\Type\ChatMemberOwner;
use Phptg\BotApi\Type\InaccessibleMessage;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;

final readonly class TelegramSettingsAuthorization
{
    public function __construct(private TelegramChatMemberGateway $members) {}

    public function actorId(Update $update, TelegramContext $context): ?int
    {
        if ($update->callbackQuery !== null) {
            return $update->callbackQuery->from->id;
        }

        $message = $this->message($update);

        if (! $message instanceof Message) {
            return null;
        }

        // Anonymous administrators and channel posts are represented by sender_chat.
        if ($message->senderChat?->id === $context->instanceId) {
            return $message->senderChat->id;
        }

        if ($update->channelPost !== null && $message->chat->type === 'channel') {
            return $message->chat->id;
        }

        return $message->from?->id;
    }

    public function allows(Update $update, TelegramContext $context, int $actorId): bool
    {
        $message = $this->message($update);
        $chatType = $message?->chat->type;

        if ($chatType === 'private') {
            return $update->callbackQuery?->from->id === $actorId
                || ($message instanceof Message && $message->from?->id === $actorId);
        }

        if (! in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            return false;
        }

        // Telegram only emits channel_post for a post accepted by that channel.
        if ($update->channelPost !== null && $chatType === 'channel' && $actorId === $context->instanceId) {
            return true;
        }

        // sender_chat equal to the destination chat is Telegram's authenticated
        // representation of an anonymous administrator. Linked-channel senders
        // are intentionally not trusted.
        if ($message instanceof Message
            && $message->senderChat?->id === $context->instanceId
            && $actorId === $context->instanceId) {
            return true;
        }

        $member = $this->members->get($context->instanceId, $actorId);

        return $member instanceof ChatMemberOwner
            || ($member instanceof ChatMemberAdministrator && $member->canManageChat);
    }

    private function message(Update $update): Message|InaccessibleMessage|null
    {
        return $update->message ?? $update->channelPost ?? $update->callbackQuery?->message;
    }
}
