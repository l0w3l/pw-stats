<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\PixelWorld\Analytics\PointsThresholdData;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class CurrentPointsThresholdAnalytics
{
    /** @return list<PointsThresholdData> */
    public function get(): array
    {
        $rows = DB::table('pixel_world_leaderboard_periods as periods')
            ->leftJoin(
                'pixel_world_leaderboard_period_entries as entries',
                function (JoinClause $join): void {
                    $join->on('entries.period_id', '=', 'periods.id')
                        ->where('entries.points', '>=', 50);
                },
            )
            ->whereIn('periods.range', array_column(LeaderboardRange::cases(), 'value'))
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('pixel_world_leaderboard_periods as newer_periods')
                    ->whereColumn('newer_periods.range', 'periods.range')
                    ->where(function (Builder $query): void {
                        $query->whereColumn('newer_periods.period_start', '>', 'periods.period_start')
                            ->orWhere(function (Builder $query): void {
                                $query->whereColumn('newer_periods.period_start', 'periods.period_start')
                                    ->whereColumn('newer_periods.id', '>', 'periods.id');
                            });
                    });
            })
            ->groupBy('periods.id', 'periods.range')
            ->select('periods.range')
            ->selectRaw(
                'SUM(CASE WHEN entries.points >= ? THEN 1 ELSE 0 END) AS players_at_least_50',
                [50],
            )
            ->selectRaw(
                'SUM(CASE WHEN entries.points >= ? THEN 1 ELSE 0 END) AS players_at_least_100',
                [100],
            )
            ->selectRaw(
                'SUM(CASE WHEN entries.points >= ? THEN 1 ELSE 0 END) AS players_at_least_250',
                [250],
            )
            ->get()
            ->keyBy('range');

        return array_map(
            static function (LeaderboardRange $range) use ($rows): PointsThresholdData {
                $row = $rows->get($range->value);

                return new PointsThresholdData(
                    range: $range->value,
                    playersAtLeast50: $row === null ? null : (int) $row->players_at_least_50,
                    playersAtLeast100: $row === null ? null : (int) $row->players_at_least_100,
                    playersAtLeast250: $row === null ? null : (int) $row->players_at_least_250,
                );
            },
            LeaderboardRange::cases(),
        );
    }
}
