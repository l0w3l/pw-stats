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

            foreach ($period->entries()->orderByDesc('points')->limit(5)->get() as $entry) {
                $mostActivePlayers[] = new PlayerActivityData(
                    range: $range->value,
                    playerUuid: $entry->player_uuid,
                    nickname: $entry->nickname,
                    kills: $entry->points,
                    killsPerDay: $entry->points / $days,
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

        $previousEntries = $previous->entries()->get()->keyBy('player_uuid');
        $players = [];

        foreach ($latest->entries()->get() as $entry) {
            $previousEntry = $previousEntries->get($entry->player_uuid);

            if (! $previousEntry) {
                continue;
            }

            $killsDelta = $entry->points - $previousEntry->points;
            $rankDelta = $previousEntry->place - $entry->place;

            if ($killsDelta <= 0) {
                continue;
            }

            $players[] = new PlayerMomentumData(
                playerUuid: $entry->player_uuid,
                nickname: $entry->nickname,
                killsDelta: $killsDelta,
                rankDelta: $rankDelta,
            );
        }

        usort($players, fn (PlayerMomentumData $left, PlayerMomentumData $right): int => [
            $right->killsDelta,
            $right->rankDelta,
        ] <=> [
            $left->killsDelta,
            $left->rankDelta,
        ]);

        return array_slice($players, 0, 5);
    }
}
