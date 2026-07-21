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
use Illuminate\Database\Connection;
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
        $players = $this->normalizePlayers($players);
        $periodStart = $calendarPeriod->start->toDateString();
        $missingPlaces = $this->missingPlaces($players, $total);
        $periodAttributes = [
            'range' => $range->value,
            'period_start' => $periodStart,
            'period_end' => $calendarPeriod->end->toDateString(),
            'total' => $total,
            'entries_count' => count($players),
            'missing_places' => $missingPlaces,
            'is_partial' => $missingPlaces > 0,
            'last_collected_at' => $collectedAt,
        ];

        return DB::transaction(function () use ($range, $periodStart, $periodAttributes, $viewer, $players): PixelWorldLeaderboardPeriod {
            $timestamp = now();

            // The unique key arbitrates first creation even when cache locks are not shared.
            // Supplying complete values also ensures this row is valid at every point where
            // it could become visible on either PostgreSQL or SQLite.
            PixelWorldLeaderboardPeriod::query()->insertOrIgnore([
                ...$periodAttributes,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $period = PixelWorldLeaderboardPeriod::query()
                ->where('range', $range->value)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->firstOrFail();

            $this->upsertPlayers([$viewer, ...$players]);

            $period->fill($periodAttributes)->save();
            $this->reconcileEntries((int) $period->getKey(), $players);

            return $period->refresh();
        }, 3);
    }

    /**
     * Apply the collection buffer's last-observation-wins semantics defensively.
     *
     * @param  array<LeaderboardPlayerData>  $players
     * @return array<LeaderboardPlayerData>
     */
    private function normalizePlayers(array $players): array
    {
        $playersByUuid = [];
        $uuidByPlace = [];

        foreach ($players as $player) {
            $previousAtPlace = $uuidByPlace[$player->place] ?? null;

            if ($previousAtPlace !== null) {
                unset($playersByUuid[$previousAtPlace]);
            }

            if (isset($playersByUuid[$player->uuid])) {
                unset($uuidByPlace[$playersByUuid[$player->uuid]->place]);
            }

            $playersByUuid[$player->uuid] = $player;
            $uuidByPlace[$player->place] = $player->uuid;
        }

        return array_values($playersByUuid);
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

        $currentByUuid = [];

        foreach (array_chunk(array_keys($playersByUuid), 500) as $uuids) {
            foreach (PixelWorldPlayer::query()->whereIn('uuid', $uuids)->get() as $current) {
                $currentByUuid[$current->uuid] = $current;
            }
        }

        $missing = array_diff_key($playersByUuid, $currentByUuid);

        foreach (array_chunk(array_values($missing), 500) as $chunk) {
            PixelWorldPlayer::query()->insertOrIgnore(array_map(static fn (LeaderboardPlayerData $player): array => [
                ...self::playerValues($player),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $chunk));
        }

        // A player can be inserted concurrently by a writer for another period. Re-read only
        // missing candidates so that an insert conflict still converges to this collection.
        foreach (array_chunk(array_keys($missing), 500) as $uuids) {
            foreach (PixelWorldPlayer::query()->whereIn('uuid', $uuids)->get() as $current) {
                $currentByUuid[$current->uuid] = $current;
            }
        }

        $changes = [];
        $changedIds = [];

        foreach ($playersByUuid as $uuid => $player) {
            $current = $currentByUuid[$uuid] ?? null;

            if ($current === null) {
                continue;
            }

            foreach (self::playerValues($player) as $column => $value) {
                if ($column === 'uuid' || $this->sameValue($column, $current->{$column}, $value)) {
                    continue;
                }

                $changes[$column][$uuid] = $value;
                $changedIds[$uuid] = $timestamp;
            }
        }

        foreach ($changes as $column => $values) {
            $this->bulkCaseUpdate('pixel_world_players', 'uuid', $column, $values);
        }

        if ($changedIds !== []) {
            $this->bulkCaseUpdate('pixel_world_players', 'uuid', 'updated_at', $changedIds);
        }
    }

    /** @param array<LeaderboardPlayerData> $players */
    private function reconcileEntries(int $periodId, array $players): void
    {
        $desiredByUuid = [];

        foreach ($players as $player) {
            $desiredByUuid[$player->uuid] = self::entryValues($periodId, $player);
        }

        $currentByUuid = [];
        PixelWorldLeaderboardPeriodEntry::query()
            ->where('period_id', $periodId)
            ->orderBy('id')
            ->chunkById(1000, function ($entries) use (&$currentByUuid): void {
                foreach ($entries as $entry) {
                    $currentByUuid[$entry->player_uuid] = $entry;
                }
            });

        $deletedIds = [];

        foreach (array_diff_key($currentByUuid, $desiredByUuid) as $entry) {
            $deletedIds[] = (int) $entry->getKey();
        }

        foreach (array_chunk($deletedIds, 500) as $ids) {
            PixelWorldLeaderboardPeriodEntry::query()->whereKey($ids)->delete();
        }

        $changes = [];

        foreach (array_intersect_key($desiredByUuid, $currentByUuid) as $uuid => $desired) {
            $current = $currentByUuid[$uuid];

            foreach ($desired as $column => $value) {
                if ($column === 'period_id' || $column === 'player_uuid' || $this->sameValue($column, $current->{$column}, $value)) {
                    continue;
                }

                $changes[$column][(int) $current->getKey()] = $value;
            }
        }

        // Move every changed rank out of the positive rank namespace first. This makes cycles
        // (including direct swaps) safe under both immediate PostgreSQL and SQLite unique keys.
        if (isset($changes['place'])) {
            $temporaryPlaces = [];
            $temporaryPlace = -1;

            foreach ($changes['place'] as $id => $_place) {
                $temporaryPlaces[$id] = $temporaryPlace--;
            }

            $this->bulkCaseUpdate('pixel_world_leaderboard_period_entries', 'id', 'place', $temporaryPlaces);
        }

        foreach ($changes as $column => $values) {
            $this->bulkCaseUpdate('pixel_world_leaderboard_period_entries', 'id', $column, $values);
        }

        $newEntries = array_diff_key($desiredByUuid, $currentByUuid);

        foreach (array_chunk(array_values($newEntries), 500) as $chunk) {
            PixelWorldLeaderboardPeriodEntry::query()->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private static function playerValues(LeaderboardPlayerData $player): array
    {
        return [
            'uuid' => $player->uuid,
            'image_url' => $player->imageUrl,
            'mask_image_url' => $player->maskImageUrl,
            'level' => $player->level,
            'nickname' => $player->nickname,
            'has_premium' => $player->hasPremium,
        ];
    }

    /** @return array<string, mixed> */
    private static function entryValues(int $periodId, LeaderboardPlayerData $player): array
    {
        return [
            'period_id' => $periodId,
            'player_uuid' => $player->uuid,
            'place' => $player->place,
            'points' => $player->points,
            'nickname' => $player->nickname,
            'level' => $player->level,
            'has_premium' => $player->hasPremium,
            'image_url' => $player->imageUrl,
            'mask_image_url' => $player->maskImageUrl,
        ];
    }

    private function sameValue(string $column, mixed $current, mixed $desired): bool
    {
        return match ($column) {
            'place', 'points', 'level' => (int) $current === $desired,
            'has_premium' => (bool) $current === $desired,
            default => $current === $desired,
        };
    }

    /**
     * Update one column for many rows without touching rows for which that column is unchanged.
     *
     * @param  array<int|string, mixed>  $valuesByKey
     */
    private function bulkCaseUpdate(string $table, string $keyColumn, string $column, array $valuesByKey): void
    {
        $connection = DB::connection();

        foreach (array_chunk($valuesByKey, 300, true) as $chunk) {
            $this->executeCaseUpdate($connection, $table, $keyColumn, $column, $chunk);
        }
    }

    /** @param array<int|string, mixed> $valuesByKey */
    private function executeCaseUpdate(
        Connection $connection,
        string $table,
        string $keyColumn,
        string $column,
        array $valuesByKey,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedKey = $grammar->wrap($keyColumn);
        $wrappedColumn = $grammar->wrap($column);
        $cases = [];
        $bindings = [];

        foreach ($valuesByKey as $key => $value) {
            $cases[] = 'when ? then ?';
            $bindings[] = $key;
            $bindings[] = $value;
        }

        $keys = array_keys($valuesByKey);
        $bindings = [...$bindings, ...$keys];
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $sql = "update {$wrappedTable} set {$wrappedColumn} = case {$wrappedKey} ".implode(' ', $cases)
            ." else {$wrappedColumn} end where {$wrappedKey} in ({$placeholders})";

        $connection->update($sql, $bindings);
    }
}
