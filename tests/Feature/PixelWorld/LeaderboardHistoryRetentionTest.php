<?php

use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldLeaderboardPeriodEntry;
use App\Models\PixelWorldPlayer;
use App\Queries\LeaderboardAnalytics;
use App\Queries\PeriodPlayerCountHistory;
use App\Services\PixelWorld\Leaderboard\LeaderboardHistoryRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Zero deliberately exercises the non-configurable hard protection of the latest pair.
    config()->set('pixel_world_retention.leaderboard_detailed_periods', [
        'day' => 0,
        'week' => 0,
        'month' => 0,
    ]);
    config()->set('pixel_world_retention.leaderboard_entry_chunk_size', 2);
});

test('it prunes details in bounded idempotent chunks while preserving analytics and all period metadata', function () {
    $periods = [
        retentionPeriod('day', '2026-07-01', '2026-07-01', 80),
        retentionPeriod('day', '2026-07-18', '2026-07-18', 90),
        retentionPeriod('day', '2026-07-19', '2026-07-19', 100),
        retentionPeriod('day', '2026-07-20', '2026-07-20', 105),
        retentionPeriod('week', '2026-06-30', '2026-07-06', 400),
        retentionPeriod('week', '2026-07-13', '2026-07-19', 450),
        retentionPeriod('week', '2026-07-20', '2026-07-26', 500),
        retentionPeriod('month', '2026-05-01', '2026-05-31', 800),
        retentionPeriod('month', '2026-06-01', '2026-06-30', 900),
        retentionPeriod('month', '2026-07-01', '2026-07-31', 1000),
    ];

    foreach ($periods as $index => $period) {
        retentionEntry($period, "history-player-{$index}", 1, $index + 1);
    }

    // Current and previous DAY entries must share a player for momentum.
    retentionEntry($periods[2], 'runner', 10, 10);
    retentionEntry($periods[3], 'runner', 7, 15);

    $retention = app(LeaderboardHistoryRetention::class);
    $dryRun = $retention->run(dryRun: true);

    expect($dryRun)->toMatchArray([
        'expired_periods' => 4,
        'expired_entries' => 4,
        'selected_entries' => 2,
        'deleted_entries' => 0,
        'dry_run' => true,
    ])->and(PixelWorldLeaderboardPeriodEntry::query()->count())->toBe(12);

    $first = $retention->run();
    $second = $retention->run();
    $idempotent = $retention->run();

    expect($first['deleted_entries'])->toBe(2)
        ->and($second['deleted_entries'])->toBe(2)
        ->and($idempotent['deleted_entries'])->toBe(0)
        ->and($idempotent['expired_entries'])->toBe(0)
        ->and(PixelWorldLeaderboardPeriod::query()->count())->toBe(10)
        ->and(PixelWorldLeaderboardPeriodEntry::query()->count())->toBe(8);

    foreach (['day', 'week', 'month'] as $range) {
        $protectedIds = PixelWorldLeaderboardPeriod::query()
            ->where('range', $range)
            ->orderByDesc('period_start')
            ->limit(2)
            ->pluck('id');

        expect(PixelWorldLeaderboardPeriodEntry::query()
            ->whereIn('period_id', $protectedIds)
            ->count())->toBe($range === 'day' ? 4 : 2);
    }

    $analytics = app(LeaderboardAnalytics::class)->get();
    $dayTrend = collect($analytics->playerCountTrends)->firstWhere('range', 'day');

    expect($dayTrend->current)->toBe(105)
        ->and($dayTrend->previous)->toBe(100)
        ->and($dayTrend->delta)->toBe(5)
        ->and(collect($analytics->mostActivePlayers)->where('range', 'week'))->not->toBeEmpty()
        ->and(collect($analytics->mostActivePlayers)->where('range', 'month'))->not->toBeEmpty()
        ->and(collect($analytics->momentumPlayers)->firstWhere('nickname', 'runner')->killsDelta)->toBe(5);

    $chart = app(PeriodPlayerCountHistory::class)->get(['day' => 4, 'week' => 3, 'month' => 3]);

    expect(collect($chart->series)->firstWhere('range', 'day')->points)->toHaveCount(4)
        ->and(collect($chart->series)->firstWhere('range', 'week')->points)->toHaveCount(3)
        ->and(collect($chart->series)->firstWhere('range', 'month')->points)->toHaveCount(3);
});

function retentionPeriod(string $range, string $start, string $end, int $total): PixelWorldLeaderboardPeriod
{
    return PixelWorldLeaderboardPeriod::query()->create([
        'range' => $range,
        'period_start' => $start,
        'period_end' => $end,
        'total' => $total,
        'entries_count' => 1,
        'missing_places' => 0,
        'is_partial' => false,
        'last_collected_at' => "{$start} 23:00:00",
    ]);
}

function retentionEntry(
    PixelWorldLeaderboardPeriod $period,
    string $nickname,
    int $place,
    int $points,
): void {
    PixelWorldPlayer::query()->firstOrCreate(['uuid' => $nickname], [
        'image_url' => null,
        'mask_image_url' => null,
        'level' => 1,
        'nickname' => $nickname,
        'has_premium' => false,
    ]);

    PixelWorldLeaderboardPeriodEntry::query()->create([
        'period_id' => $period->id,
        'player_uuid' => $nickname,
        'place' => $place,
        'points' => $points,
        'nickname' => $nickname,
        'level' => 1,
        'has_premium' => false,
    ]);
}
