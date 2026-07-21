<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\TelegramNotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendTelegramNotifications extends Command
{
    protected $signature = 'telegram:notifications:send';

    protected $description = 'Send due recurring Telegram analytics notifications';

    public function handle(TelegramNotificationDispatcher $dispatcher): int
    {
        $claimed = $dispatcher->dispatch(CarbonImmutable::now('UTC')->startOfMinute());
        $this->info("Queued {$claimed} Telegram notification(s).");

        return self::SUCCESS;
    }
}
