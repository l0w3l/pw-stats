<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Telegram\TelegramNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramNotificationBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT = 120;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** @param list<int> $deliveryIds */
    public function __construct(
        public readonly array $deliveryIds,
        public readonly string $claimToken,
    ) {
        $this->onQueue((string) config('telegram_notifications.delivery.queue', 'default'));
    }

    public function handle(TelegramNotificationDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->deliveryIds, $this->claimToken);
    }
}
