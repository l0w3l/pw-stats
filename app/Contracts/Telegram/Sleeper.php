<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

interface Sleeper
{
    public function milliseconds(int $milliseconds): void;
}
