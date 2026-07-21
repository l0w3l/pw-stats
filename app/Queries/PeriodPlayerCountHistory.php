<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\PixelWorld\Analytics\PlayerCountChartData;
use App\Data\PixelWorld\Analytics\PlayerCountChartPoint;
use App\Data\PixelWorld\Analytics\PlayerCountChartSeries;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use InvalidArgumentException;

class PeriodPlayerCountHistory
{
    /** @param array<string, int> $periodLimits */
    public function get(array $periodLimits): PlayerCountChartData
    {
        $series = [];

        foreach (LeaderboardRange::cases() as $range) {
            $maximum = $periodLimits[$range->value] ?? 0;

            if ($maximum < 2) {
                throw new InvalidArgumentException("The {$range->value} period chart limit must be at least two.");
            }

            // The bounded candidate set keeps queries cheap while retaining endpoint-preserving downsampling.
            $points = PixelWorldLeaderboardPeriod::query()
                ->where('range', $range->value)
                ->orderByDesc('period_start')
                ->limit($maximum * 4)
                ->get(['id', 'total', 'period_start', 'last_collected_at', 'is_partial'])
                ->reverse()
                ->values()
                ->map(static fn (PixelWorldLeaderboardPeriod $period): PlayerCountChartPoint => new PlayerCountChartPoint(
                    periodId: $period->getKey(),
                    total: $period->total,
                    periodStart: $period->period_start,
                    collectedAt: $period->last_collected_at,
                    isPartial: $period->is_partial,
                ))
                ->all();

            $series[] = new PlayerCountChartSeries($range->value, $this->downsample($points, $maximum));
        }

        return new PlayerCountChartData($series, $periodLimits);
    }

    /**
     * @param  array<PlayerCountChartPoint>  $points
     * @return array<PlayerCountChartPoint>
     */
    private function downsample(array $points, int $maximum): array
    {
        if (count($points) <= $maximum) {
            return $points;
        }

        $sampled = [];

        for ($index = 0; $index < $maximum; $index++) {
            $sourceIndex = (int) round($index * (count($points) - 1) / ($maximum - 1));
            $sampled[] = $points[$sourceIndex];
        }

        return $sampled;
    }
}
