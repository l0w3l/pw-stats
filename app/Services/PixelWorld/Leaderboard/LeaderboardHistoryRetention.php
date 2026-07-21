<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeaderboardHistoryRetention
{
    private const MINIMUM_DETAILED_PERIODS = 2;

    private const MAXIMUM_CHUNK_SIZE = 10000;

    /**
     * @return array{
     *     expired_periods: int,
     *     expired_entries: int,
     *     selected_entries: int,
     *     deleted_entries: int,
     *     dry_run: bool
     * }
     */
    public function run(bool $dryRun = false): array
    {
        $chunkSize = (int) config('pixel_world_retention.leaderboard_entry_chunk_size');

        if ($chunkSize < 1 || $chunkSize > self::MAXIMUM_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'Leaderboard entry retention chunk size must be between 1 and %d.',
                self::MAXIMUM_CHUNK_SIZE,
            ));
        }

        $retainedPeriodIds = $this->retainedPeriodIds();
        $expiredPeriods = $this->expiredPeriodsQuery($retainedPeriodIds);
        $expiredPeriodCount = (clone $expiredPeriods)->count();
        $expiredEntryCount = $this->expiredEntriesQuery($retainedPeriodIds)->count();

        // Candidate period IDs and entry IDs are both bounded. period_id and id are indexed,
        // allowing repeated invocations to make predictable progress without long deletes.
        $candidatePeriodIds = (clone $expiredPeriods)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('pixel_world_leaderboard_period_entries')
                    ->whereColumn('pixel_world_leaderboard_period_entries.period_id', 'pixel_world_leaderboard_periods.id');
            })
            ->orderBy('period_start')
            ->orderBy('id')
            ->limit($chunkSize)
            ->pluck('id');

        $entryIds = $candidatePeriodIds->isEmpty()
            ? collect()
            : DB::table('pixel_world_leaderboard_period_entries')
                ->whereIn('period_id', $candidatePeriodIds)
                ->orderBy('period_id')
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

        $deletedEntries = 0;

        if (! $dryRun && $entryIds->isNotEmpty()) {
            $deletedEntries = DB::table('pixel_world_leaderboard_period_entries')
                ->whereIn('id', $entryIds)
                ->delete();
        }

        return [
            'expired_periods' => $expiredPeriodCount,
            'expired_entries' => $expiredEntryCount,
            'selected_entries' => $entryIds->count(),
            'deleted_entries' => $deletedEntries,
            'dry_run' => $dryRun,
        ];
    }

    /** @return array<int, int> */
    private function retainedPeriodIds(): array
    {
        $configuredWindows = config('pixel_world_retention.leaderboard_detailed_periods');

        if (! is_array($configuredWindows)) {
            throw new InvalidArgumentException('Leaderboard detailed-period retention must be configured per range.');
        }

        $ids = [];

        foreach (LeaderboardRange::cases() as $range) {
            $configured = (int) ($configuredWindows[$range->value] ?? -1);

            if ($configured < 0) {
                throw new InvalidArgumentException("Leaderboard detailed-period retention for {$range->value} must be zero or greater.");
            }

            $ids = array_merge($ids, DB::table('pixel_world_leaderboard_periods')
                ->where('range', $range->value)
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->limit(max(self::MINIMUM_DETAILED_PERIODS, $configured))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all());
        }

        return $ids;
    }

    /** @param array<int, int> $retainedPeriodIds */
    private function expiredPeriodsQuery(array $retainedPeriodIds): Builder
    {
        return DB::table('pixel_world_leaderboard_periods')
            ->whereIn('range', array_map(
                static fn (LeaderboardRange $range): string => $range->value,
                LeaderboardRange::cases(),
            ))
            ->when(
                $retainedPeriodIds !== [],
                static fn (Builder $query): Builder => $query->whereNotIn('id', $retainedPeriodIds),
            );
    }

    /** @param array<int, int> $retainedPeriodIds */
    private function expiredEntriesQuery(array $retainedPeriodIds): Builder
    {
        return DB::table('pixel_world_leaderboard_period_entries as entries')
            ->join('pixel_world_leaderboard_periods as periods', 'periods.id', '=', 'entries.period_id')
            ->whereIn('periods.range', array_map(
                static fn (LeaderboardRange $range): string => $range->value,
                LeaderboardRange::cases(),
            ))
            ->when(
                $retainedPeriodIds !== [],
                static fn (Builder $query): Builder => $query->whereNotIn('periods.id', $retainedPeriodIds),
            );
    }
}
