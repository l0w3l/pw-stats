<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\Sleeper;

class NativeSleeper implements Sleeper
{
    public function milliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
