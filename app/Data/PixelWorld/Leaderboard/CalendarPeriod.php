<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Leaderboard;

use Carbon\CarbonImmutable;

final readonly class CalendarPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}
}
