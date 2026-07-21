<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Database\Eloquent\Collection;

class LatestLeaderboardPeriods
{
    /** @return Collection<int, PixelWorldLeaderboardPeriod> */
    public function get(?LeaderboardRange $range = null): Collection
    {
        $periods = new Collection;

        foreach ($range ? [$range] : LeaderboardRange::cases() as $selectedRange) {
            $period = PixelWorldLeaderboardPeriod::query()
                ->where('range', $selectedRange->value)
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->first();

            if ($period !== null) {
                $periods->push($period);
            }
        }

        return $periods;
    }
}
