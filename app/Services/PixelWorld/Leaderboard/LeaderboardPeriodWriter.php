<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Data\PixelWorld\Leaderboard\CalendarPeriod;
use App\Data\PixelWorld\Stats\LeaderboardPlayerData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldLeaderboardPeriodEntry;
use App\Models\PixelWorldPlayer;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LeaderboardPeriodWriter
{
    /**
     * @param  array<LeaderboardPlayerData>  $players
     */
    public function replace(
        LeaderboardRange $range,
        CalendarPeriod $calendarPeriod,
        LeaderboardPlayerData $viewer,
        array $players,
        int $total,
        CarbonImmutable $collectedAt,
    ): PixelWorldLeaderboardPeriod {
        return DB::transaction(function () use ($range, $calendarPeriod, $viewer, $players, $total, $collectedAt): PixelWorldLeaderboardPeriod {
            $this->upsertPlayers([$viewer, ...$players]);

            $period = PixelWorldLeaderboardPeriod::query()
                ->where('range', $range->value)
                ->whereDate('period_start', $calendarPeriod->start->toDateString())
                ->lockForUpdate()
                ->first() ?? new PixelWorldLeaderboardPeriod;
            $missingPlaces = $this->missingPlaces($players, $total);

            $period->fill([
                'range' => $range->value,
                'period_start' => $calendarPeriod->start->toDateString(),
                'period_end' => $calendarPeriod->end->toDateString(),
                'total' => $total,
                'entries_count' => count($players),
                'missing_places' => $missingPlaces,
                'is_partial' => $missingPlaces > 0,
                'last_collected_at' => $collectedAt,
            ])->save();

            $period->entries()->delete();

            foreach (array_chunk($players, 500) as $chunk) {
                PixelWorldLeaderboardPeriodEntry::query()->insert(array_map(
                    static fn (LeaderboardPlayerData $player): array => [
                        'period_id' => $period->getKey(),
                        'player_uuid' => $player->uuid,
                        'place' => $player->place,
                        'points' => $player->points,
                        'nickname' => $player->nickname,
                        'level' => $player->level,
                        'has_premium' => $player->hasPremium,
                        'image_url' => $player->imageUrl,
                        'mask_image_url' => $player->maskImageUrl,
                    ],
                    $chunk,
                ));
            }

            return $period->refresh();
        }, 3);
    }

    /** @param array<LeaderboardPlayerData> $players */
    private function missingPlaces(array $players, int $total): int
    {
        $places = array_fill_keys(array_map(static fn (LeaderboardPlayerData $player): int => $player->place, $players), true);
        $missing = 0;

        for ($place = 1; $place <= $total; $place++) {
            $missing += isset($places[$place]) ? 0 : 1;
        }

        return $missing;
    }

    /** @param array<LeaderboardPlayerData> $players */
    private function upsertPlayers(array $players): void
    {
        $timestamp = now();
        $playersByUuid = [];

        foreach ($players as $player) {
            $playersByUuid[$player->uuid] = $player;
        }

        foreach (array_chunk(array_values($playersByUuid), 500) as $chunk) {
            PixelWorldPlayer::query()->upsert(array_map(static fn (LeaderboardPlayerData $player): array => [
                'uuid' => $player->uuid,
                'image_url' => $player->imageUrl,
                'mask_image_url' => $player->maskImageUrl,
                'level' => $player->level,
                'nickname' => $player->nickname,
                'has_premium' => $player->hasPremium,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $chunk), ['uuid'], ['image_url', 'mask_image_url', 'level', 'nickname', 'has_premium', 'updated_at']);
        }
    }
}
