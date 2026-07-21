<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A multi-gigabyte legacy table must not be copied row-by-row through PHP or kept as
     * high-frequency history. Only the latest completed snapshot of each calendar period
     * is retained, and its entries are copied by the database with INSERT ... SELECT.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $hasLegacySnapshots = Schema::hasTable('pixel_world_leaderboard_snapshots');
        $hasLegacyEntries = Schema::hasTable('pixel_world_leaderboard_entries');

        // A crash after the final legacy DROP leaves complete period tables but no migration row.
        if (! $hasLegacySnapshots && ! $hasLegacyEntries
            && Schema::hasTable('pixel_world_leaderboard_periods')
            && Schema::hasTable('pixel_world_leaderboard_period_entries')) {
            return;
        }

        // Entries are dropped only after period data and constraints are complete. Resume the
        // one possible interruption point between the two final legacy DROP statements.
        if ($hasLegacySnapshots && ! $hasLegacyEntries
            && Schema::hasTable('pixel_world_leaderboard_periods')
            && Schema::hasTable('pixel_world_leaderboard_period_entries')) {
            Schema::drop('pixel_world_leaderboard_snapshots');

            return;
        }

        if ($hasLegacySnapshots !== $hasLegacyEntries) {
            throw new RuntimeException('Legacy leaderboard snapshot tables are incomplete; refusing a destructive migration.');
        }

        Schema::dropIfExists('pixel_world_leaderboard_period_entries');
        Schema::dropIfExists('pixel_world_leaderboard_periods');
        $this->createPeriodTables(withEntryConstraints: false);

        if ($hasLegacySnapshots) {
            $this->migrateLatestCalendarPeriods();
            $this->addPeriodEntryConstraints();

            // Keep all 4 GB of legacy data available until every selected period is copied and indexed.
            Schema::drop('pixel_world_leaderboard_entries');
            Schema::drop('pixel_world_leaderboard_snapshots');
        } else {
            $this->addPeriodEntryConstraints();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_world_leaderboard_period_entries');
        Schema::dropIfExists('pixel_world_leaderboard_periods');

        // Rolling back restores the legacy schema, but cannot reconstruct discarded high-frequency data.
        Schema::create('pixel_world_leaderboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('range', 16);
            $table->foreignUuid('viewer_uuid')->constrained('pixel_world_players', 'uuid')->restrictOnDelete();
            $table->unsignedInteger('total');
            $table->unsignedInteger('entries_count')->default(0);
            $table->unsignedInteger('missing_places')->default(0);
            $table->boolean('is_partial')->default(false);
            $table->unsignedInteger('pages_total');
            $table->unsignedInteger('pages_collected')->default(0);
            $table->string('status', 16);
            $table->text('failure_reason')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['range', 'status', 'captured_at']);
        });

        Schema::create('pixel_world_leaderboard_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('pixel_world_leaderboard_snapshots')->cascadeOnDelete();
            $table->foreignUuid('player_uuid')->constrained('pixel_world_players', 'uuid')->restrictOnDelete();
            $table->unsignedInteger('place');
            $table->unsignedBigInteger('points');
            $table->string('nickname');
            $table->unsignedInteger('level');
            $table->boolean('has_premium');
            $table->string('image_url')->nullable();
            $table->string('mask_image_url')->nullable();
            $table->unique(['snapshot_id', 'player_uuid']);
            $table->unique(['snapshot_id', 'place']);
        });
    }

    private function migrateLatestCalendarPeriods(): void
    {
        $selected = [];

        DB::table('pixel_world_leaderboard_snapshots')
            ->select([
                'id',
                'range',
                'total',
                'entries_count',
                'missing_places',
                'is_partial',
                'captured_at',
                'completed_at',
            ])
            ->where('status', 'completed')
            ->lazyById(1000)
            ->each(function (object $snapshot) use (&$selected): void {
                $capturedAt = CarbonImmutable::parse((string) $snapshot->captured_at, 'UTC')
                    ->setTimezone((string) config('app.timezone'));
                [$periodStart, $periodEnd] = $this->periodBoundaries((string) $snapshot->range, $capturedAt);
                $key = $snapshot->range.'|'.$periodStart;
                $collectedAt = CarbonImmutable::parse(
                    (string) ($snapshot->completed_at ?: $snapshot->captured_at),
                    'UTC',
                );
                $current = $selected[$key] ?? null;

                if ($current !== null
                    && ($collectedAt->lessThan($current['last_collected_at'])
                        || ($collectedAt->equalTo($current['last_collected_at']) && $snapshot->id < $current['snapshot_id']))) {
                    return;
                }

                $selected[$key] = [
                    'snapshot_id' => (int) $snapshot->id,
                    'range' => (string) $snapshot->range,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'total' => (int) $snapshot->total,
                    'entries_count' => (int) $snapshot->entries_count,
                    'missing_places' => (int) $snapshot->missing_places,
                    'is_partial' => (bool) $snapshot->is_partial,
                    'last_collected_at' => $collectedAt,
                ];
            });

        uasort($selected, static fn (array $left, array $right): int => [
            $left['range'],
            $left['period_start'],
        ] <=> [
            $right['range'],
            $right['period_start'],
        ]);

        foreach ($selected as $period) {
            $periodId = DB::table('pixel_world_leaderboard_periods')->insertGetId([
                'range' => $period['range'],
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'total' => $period['total'],
                'entries_count' => $period['entries_count'],
                'missing_places' => $period['missing_places'],
                'is_partial' => $period['is_partial'],
                'last_collected_at' => $period['last_collected_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('pixel_world_leaderboard_period_entries')->insertUsing(
                [
                    'period_id',
                    'player_uuid',
                    'place',
                    'points',
                    'nickname',
                    'level',
                    'has_premium',
                    'image_url',
                    'mask_image_url',
                ],
                DB::table('pixel_world_leaderboard_entries')
                    ->selectRaw('? as period_id', [$periodId])
                    ->addSelect([
                        'player_uuid',
                        'place',
                        'points',
                        'nickname',
                        'level',
                        'has_premium',
                        'image_url',
                        'mask_image_url',
                    ])
                    ->where('snapshot_id', $period['snapshot_id']),
            );
        }
    }

    /** @return array{string, string} */
    private function periodBoundaries(string $range, CarbonImmutable $date): array
    {
        [$start, $end] = match ($range) {
            'day' => [$date->startOfDay(), $date->endOfDay()],
            'week' => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)],
            'month' => [$date->startOfMonth(), $date->endOfMonth()],
            default => throw new RuntimeException("Unknown legacy leaderboard range: {$range}"),
        };

        return [$start->toDateString(), $end->toDateString()];
    }

    private function createPeriodTables(bool $withEntryConstraints): void
    {
        Schema::create('pixel_world_leaderboard_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('range', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('total');
            $table->unsignedInteger('entries_count');
            $table->unsignedInteger('missing_places')->default(0);
            $table->boolean('is_partial')->default(false);
            $table->timestamp('last_collected_at');
            $table->timestamps();
            $table->unique(['range', 'period_start']);
            $table->index(['range', 'period_start', 'last_collected_at'], 'pw_leaderboard_periods_latest_index');
        });

        Schema::create('pixel_world_leaderboard_period_entries', function (Blueprint $table) use ($withEntryConstraints): void {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->uuid('player_uuid');
            $table->unsignedInteger('place');
            $table->unsignedBigInteger('points');
            $table->string('nickname');
            $table->unsignedInteger('level');
            $table->boolean('has_premium');
            $table->string('image_url')->nullable();
            $table->string('mask_image_url')->nullable();

            if ($withEntryConstraints) {
                $table->foreign('period_id')->references('id')->on('pixel_world_leaderboard_periods')->cascadeOnDelete();
                $table->foreign('player_uuid')->references('uuid')->on('pixel_world_players')->restrictOnDelete();
                $table->unique(['period_id', 'player_uuid']);
                $table->unique(['period_id', 'place']);
            }
        });
    }

    private function addPeriodEntryConstraints(): void
    {
        Schema::table('pixel_world_leaderboard_period_entries', function (Blueprint $table): void {
            $table->foreign('period_id')->references('id')->on('pixel_world_leaderboard_periods')->cascadeOnDelete();
            $table->foreign('player_uuid')->references('uuid')->on('pixel_world_players')->restrictOnDelete();
            $table->unique(['period_id', 'player_uuid']);
            $table->unique(['period_id', 'place']);
        });
    }
};
