<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerActivityData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PlayerMomentumData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LeaderboardAnalytics
{
    public function get(): LeaderboardAnalyticsData
    {
        $periods = [];
        $playerCountTrends = [];
        $latestCollectedAt = null;

        foreach (LeaderboardRange::cases() as $range) {
            $pair = $this->latestPair($range);
            $latest = $pair->get(0);

            if (! $latest) {
                continue;
            }

            $previous = $pair->get(1);
            $collectedAt = $latest->last_collected_at;

            if ($latestCollectedAt === null || $collectedAt->greaterThan($latestCollectedAt)) {
                $latestCollectedAt = $collectedAt;
            }

            $periods[$range->value] = $pair;
            $playerCountTrends[] = new PlayerCountTrendData(
                range: $range->value,
                current: $latest->total,
                previous: $previous?->total,
                delta: $previous ? $latest->total - $previous->total : null,
            );
        }

        $mostActivePlayers = [];

        foreach ([LeaderboardRange::Week, LeaderboardRange::Month] as $range) {
            $period = $periods[$range->value][0] ?? null;

            if (! $period) {
                continue;
            }

            $days = $range === LeaderboardRange::Week ? 7 : 30;

            $entries = DB::table('pixel_world_leaderboard_period_entries')
                ->select(['player_uuid', 'nickname', 'points'])
                ->where('period_id', $period->id)
                ->orderByDesc('points')
                ->limit(5)
                ->get();

            foreach ($entries as $entry) {
                $mostActivePlayers[] = new PlayerActivityData(
                    range: $range->value,
                    playerUuid: (string) $entry->player_uuid,
                    nickname: (string) $entry->nickname,
                    kills: (int) $entry->points,
                    killsPerDay: (int) $entry->points / $days,
                );
            }
        }

        $momentumPlayers = $this->momentum($periods['day'] ?? new Collection);

        return new LeaderboardAnalyticsData(
            playerCountTrends: $playerCountTrends,
            mostActivePlayers: $mostActivePlayers,
            momentumPlayers: $momentumPlayers,
            latestCollectedAt: $latestCollectedAt,
        );
    }

    /** @return Collection<int, PixelWorldLeaderboardPeriod> */
    private function latestPair(LeaderboardRange $range): Collection
    {
        return PixelWorldLeaderboardPeriod::query()
            ->where('range', $range->value)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(2)
            ->get();
    }

    /**
     * @param  Collection<int, PixelWorldLeaderboardPeriod>  $periods
     * @return array<PlayerMomentumData>
     */
    private function momentum(Collection $periods): array
    {
        $latest = $periods->get(0);
        $previous = $periods->get(1);

        if (! $latest || ! $previous) {
            return [];
        }

        $entries = DB::table('pixel_world_leaderboard_period_entries as current_entries')
            ->join(
                'pixel_world_leaderboard_period_entries as previous_entries',
                'previous_entries.player_uuid',
                '=',
                'current_entries.player_uuid',
            )
            ->select([
                'current_entries.player_uuid',
                'current_entries.nickname',
            ])
            ->selectRaw('current_entries.points - previous_entries.points as kills_delta')
            ->selectRaw('previous_entries.place - current_entries.place as rank_delta')
            ->where('current_entries.period_id', $latest->id)
            ->where('previous_entries.period_id', $previous->id)
            ->whereColumn('current_entries.points', '>', 'previous_entries.points')
            ->orderByDesc('kills_delta')
            ->orderByDesc('rank_delta')
            ->orderBy('current_entries.player_uuid')
            ->limit(5)
            ->get();

        return $entries
            ->map(static fn (object $entry): PlayerMomentumData => new PlayerMomentumData(
                playerUuid: (string) $entry->player_uuid,
                nickname: (string) $entry->nickname,
                killsDelta: (int) $entry->kills_delta,
                rankDelta: (int) $entry->rank_delta,
            ))
            ->all();
    }
}
