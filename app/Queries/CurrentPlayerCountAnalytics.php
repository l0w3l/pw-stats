<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldPlayerTotal;
use App\Services\PixelWorld\Leaderboard\LeaderboardCalendarPeriodResolver;
use App\Services\PixelWorld\Stats\LeaderboardRange;

class CurrentPlayerCountAnalytics
{
    public function __construct(
        private readonly LeaderboardCalendarPeriodResolver $periodResolver = new LeaderboardCalendarPeriodResolver,
    ) {}

    /** @return array<PlayerCountTrendData> */
    public function get(): array
    {
        $trends = [];

        foreach (LeaderboardRange::cases() as $range) {
            $sample = PixelWorldPlayerTotal::query()
                ->where('range', $range->value)
                ->orderByDesc('collected_at')
                ->orderByDesc('id')
                ->first();

            $trend = $sample === null
                ? $this->periodTrend($range)
                : $this->sampleTrend($range, $sample);

            if ($trend !== null) {
                $trends[] = $trend;
            }
        }

        return $trends;
    }

    private function sampleTrend(
        LeaderboardRange $range,
        PixelWorldPlayerTotal $sample,
    ): PlayerCountTrendData {
        $currentPeriod = $this->periodResolver->resolve($range, $sample->collected_at);
        $previousPeriod = $this->periodResolver->resolve($range, $currentPeriod->start->subSecond());
        $previous = PixelWorldLeaderboardPeriod::query()
            ->where('range', $range->value)
            ->whereDate('period_start', $previousPeriod->start->toDateString())
            ->orderByDesc('id')
            ->first();

        return new PlayerCountTrendData(
            range: $range->value,
            current: $sample->total,
            previous: $previous?->total,
            delta: $previous === null ? null : $sample->total - $previous->total,
        );
    }

    private function periodTrend(LeaderboardRange $range): ?PlayerCountTrendData
    {
        $periods = PixelWorldLeaderboardPeriod::query()
            ->where('range', $range->value)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(2)
            ->get();
        $current = $periods->get(0);

        if ($current === null) {
            return null;
        }

        $previous = $periods->get(1);

        return new PlayerCountTrendData(
            range: $range->value,
            current: $current->total,
            previous: $previous?->total,
            delta: $previous === null ? null : $current->total - $previous->total,
        );
    }
}
