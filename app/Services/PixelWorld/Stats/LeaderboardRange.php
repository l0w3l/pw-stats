<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Stats;

enum LeaderboardRange: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
