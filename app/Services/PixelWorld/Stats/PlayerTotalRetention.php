<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Stats;

use App\Models\PixelWorldPlayerTotal;
use App\Models\PixelWorldPlayerTotalHourly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PlayerTotalRetention
{
    /**
     * @return array{
     *     raw_rows_candidates: int,
     *     hourly_rows_candidates: int,
     *     hours_rolled_up: int,
     *     raw_rows_deleted: int,
     *     hourly_rows_deleted: int,
     *     dry_run: bool
     * }
     */
    public function run(?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $rawDays = (int) config('pixel_world_retention.player_totals_raw_days');
        $hourlyDays = (int) config('pixel_world_retention.player_totals_hourly_days');
        $hoursPerRun = (int) config('pixel_world_retention.player_totals_hours_per_run');

        if ($rawDays < 1 || $hourlyDays < $rawDays || $hoursPerRun < 1) {
            throw new InvalidArgumentException(
                'Player-total retention requires raw days >= 1, hourly days >= raw days, and hours per run >= 1.',
            );
        }

        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        // Only complete UTC hours are eligible, avoiding partial aggregates at the retention boundary.
        $rawCutoff = $now->subDays($rawDays)->startOfHour();
        $hourlyCutoff = $now->subDays($hourlyDays)->startOfHour();
        $rawRowsCandidates = PixelWorldPlayerTotal::query()
            ->where('collected_at', '<', $rawCutoff)
            ->count();
        $hourlyRowsCandidates = PixelWorldPlayerTotalHourly::query()
            ->where('hour_at', '<', $hourlyCutoff)
            ->count();

        if ($dryRun) {
            return [
                'raw_rows_candidates' => $rawRowsCandidates,
                'hourly_rows_candidates' => $hourlyRowsCandidates,
                'hours_rolled_up' => 0,
                'raw_rows_deleted' => 0,
                'hourly_rows_deleted' => 0,
                'dry_run' => true,
            ];
        }

        $hoursRolledUp = 0;
        $rawRowsDeleted = 0;

        while ($hoursRolledUp < $hoursPerRun) {
            $oldest = PixelWorldPlayerTotal::query()
                ->where('collected_at', '<', $rawCutoff)
                ->orderBy('collected_at')
                ->value('collected_at');

            if ($oldest === null) {
                break;
            }

            $hour = CarbonImmutable::parse($oldest, 'UTC')->utc()->startOfHour();
            $deleted = $this->rollUpHour($hour, $now);

            if ($deleted === 0) {
                break;
            }

            $hoursRolledUp++;
            $rawRowsDeleted += $deleted;
        }

        $hourlyIds = PixelWorldPlayerTotalHourly::query()
            ->where('hour_at', '<', $hourlyCutoff)
            ->orderBy('hour_at')
            ->orderBy('id')
            ->limit($hoursPerRun * count(LeaderboardRange::cases()))
            ->pluck('id');

        $hourlyRowsDeleted = $hourlyIds->isEmpty()
            ? 0
            : PixelWorldPlayerTotalHourly::query()->whereKey($hourlyIds)->delete();

        return [
            'raw_rows_candidates' => $rawRowsCandidates,
            'hourly_rows_candidates' => $hourlyRowsCandidates,
            'hours_rolled_up' => $hoursRolledUp,
            'raw_rows_deleted' => $rawRowsDeleted,
            'hourly_rows_deleted' => $hourlyRowsDeleted,
            'dry_run' => false,
        ];
    }

    private function rollUpHour(CarbonImmutable $hour, CarbonImmutable $writtenAt): int
    {
        return DB::transaction(function () use ($hour, $writtenAt): int {
            $samples = PixelWorldPlayerTotal::query()
                ->where('collected_at', '>=', $hour)
                ->where('collected_at', '<', $hour->addHour())
                ->orderBy('collected_at')
                ->orderBy('id')
                ->get(['id', 'range', 'total', 'collected_at']);

            if ($samples->isEmpty()) {
                return 0;
            }

            $aggregates = $samples
                ->groupBy('range')
                ->map(fn (Collection $rangeSamples, string $range): array => [
                    'range' => $range,
                    'hour_at' => $hour,
                    'sample_count' => $rangeSamples->count(),
                    'minimum_total' => $rangeSamples->min('total'),
                    'maximum_total' => $rangeSamples->max('total'),
                    'average_total' => round((float) $rangeSamples->avg('total'), 2),
                    'first_total' => $rangeSamples->first()->total,
                    'last_total' => $rangeSamples->last()->total,
                    'created_at' => $writtenAt,
                    'updated_at' => $writtenAt,
                ])
                ->values()
                ->all();

            PixelWorldPlayerTotalHourly::query()->upsert(
                $aggregates,
                ['range', 'hour_at'],
                [
                    'sample_count',
                    'minimum_total',
                    'maximum_total',
                    'average_total',
                    'first_total',
                    'last_total',
                    'updated_at',
                ],
            );

            // The upsert and deletion share a transaction, so raw data survives any aggregate failure.
            return PixelWorldPlayerTotal::query()->whereKey($samples->modelKeys())->delete();
        }, 3);
    }
}
