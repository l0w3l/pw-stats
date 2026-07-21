<?php

declare(strict_types=1);

namespace App\Services\Telegram;

interface TelegramWebAppDataProvider
{
    public function get(string $botUsername, bool $refresh = false): string;
}
