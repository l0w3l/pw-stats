<?php

declare(strict_types=1);

namespace App\Data\Telegram;

final readonly class TelegramContext
{
    public function __construct(
        public int $instanceId,
        public ?int $threadId,
        public bool $hasThreadContext = true,
    ) {}
}
