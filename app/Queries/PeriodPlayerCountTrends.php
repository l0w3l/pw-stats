<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;

class PeriodPlayerCountTrends
{
    /** @return array<PlayerCountTrendData> */
    public function get(): array
    {
        $trends = [];

        foreach (LeaderboardRange::cases() as $range) {
            $periods = PixelWorldLeaderboardPeriod::query()
                ->where('range', $range->value)
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->limit(2)
                ->get(['id', 'total']);
            $current = $periods->get(0);

            if ($current === null) {
                continue;
            }

            $previous = $periods->get(1);
            $trends[] = new PlayerCountTrendData(
                range: $range->value,
                current: $current->total,
                previous: $previous?->total,
                delta: $previous === null ? null : $current->total - $previous->total,
            );
        }

        return $trends;
    }
}
