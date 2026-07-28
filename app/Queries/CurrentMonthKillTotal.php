<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;

class CurrentMonthKillTotal
{
    public function get(): ?int
    {
        $period = PixelWorldLeaderboardPeriod::query()
            ->where('range', LeaderboardRange::Month->value)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->first();

        return $period === null
            ? null
            : (int) $period->entries()->sum('points');
    }
}
