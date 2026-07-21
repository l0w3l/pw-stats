<?php

use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldPlayer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('legacy migration keeps only the latest completed snapshot of each calendar period', function () {
    $migration = require database_path('migrations/2026_07_20_000004_replace_dirty_leaderboard_snapshots_with_periods.php');
    $migration->down();

    foreach (['viewer', 'old-player', 'latest-player', 'failed-player', 'next-day-player', 'week-player'] as $uuid) {
        PixelWorldPlayer::create([
            'uuid' => $uuid,
            'image_url' => null,
            'mask_image_url' => null,
            'level' => 1,
            'nickname' => $uuid,
            'has_premium' => false,
        ]);
    }

    $old = legacySnapshot('2026-07-20 10:00:00', 'completed');
    $latest = legacySnapshot('2026-07-20 12:00:00', 'completed');
    $failed = legacySnapshot('2026-07-20 13:00:00', 'failed');
    $nextDay = legacySnapshot('2026-07-21 09:00:00', 'completed');
    $week = legacySnapshot('2026-07-22 09:00:00', 'completed', 'week');

    legacyEntry($old, 'old-player', 10);
    legacyEntry($latest, 'latest-player', 20);
    legacyEntry($failed, 'failed-player', 30);
    legacyEntry($nextDay, 'next-day-player', 40);
    legacyEntry($week, 'week-player', 50);

    $migration->up();

    expect(Schema::hasTable('pixel_world_leaderboard_snapshots'))->toBeFalse()
        ->and(Schema::hasTable('pixel_world_leaderboard_entries'))->toBeFalse()
        ->and(PixelWorldLeaderboardPeriod::query()->count())->toBe(3);

    $firstPeriod = PixelWorldLeaderboardPeriod::query()
        ->where('range', 'day')
        ->whereDate('period_start', '2026-07-20')
        ->sole();

    expect($firstPeriod->entries()->sole()->player_uuid)->toBe('latest-player')
        ->and($firstPeriod->entries()->sole()->points)->toBe(20)
        ->and($firstPeriod->last_collected_at->toDateTimeString())->toBe('2026-07-20 12:00:00');

    $weekPeriod = PixelWorldLeaderboardPeriod::query()->where('range', 'week')->sole();

    expect($weekPeriod->period_start->toDateString())->toBe('2026-07-20')
        ->and($weekPeriod->period_end->toDateString())->toBe('2026-07-26');
});

test('legacy migration resumes after entries were dropped but snapshots remain', function () {
    $period = PixelWorldLeaderboardPeriod::query()->create([
        'range' => 'day',
        'period_start' => '2026-07-20',
        'period_end' => '2026-07-20',
        'total' => 0,
        'entries_count' => 0,
        'missing_places' => 0,
        'is_partial' => false,
        'last_collected_at' => '2026-07-20 12:00:00',
    ]);
    Schema::create('pixel_world_leaderboard_snapshots', function (Blueprint $table): void {
        $table->id();
    });
    $migration = require database_path('migrations/2026_07_20_000004_replace_dirty_leaderboard_snapshots_with_periods.php');

    $migration->up();

    expect(Schema::hasTable('pixel_world_leaderboard_snapshots'))->toBeFalse()
        ->and(Schema::hasTable('pixel_world_leaderboard_entries'))->toBeFalse()
        ->and(PixelWorldLeaderboardPeriod::query()->find($period->id)?->id)->toBe($period->id);
});

function legacySnapshot(string $capturedAt, string $status, string $range = 'day'): int
{
    return DB::table('pixel_world_leaderboard_snapshots')->insertGetId([
        'range' => $range,
        'viewer_uuid' => 'viewer',
        'total' => 1,
        'entries_count' => 1,
        'missing_places' => 0,
        'is_partial' => false,
        'pages_total' => 1,
        'pages_collected' => 1,
        'status' => $status,
        'failure_reason' => null,
        'captured_at' => $capturedAt,
        'completed_at' => $status === 'completed' ? $capturedAt : null,
        'created_at' => $capturedAt,
        'updated_at' => $capturedAt,
    ]);
}

function legacyEntry(int $snapshotId, string $playerUuid, int $points): void
{
    DB::table('pixel_world_leaderboard_entries')->insert([
        'snapshot_id' => $snapshotId,
        'player_uuid' => $playerUuid,
        'place' => 1,
        'points' => $points,
        'nickname' => $playerUuid,
        'level' => 1,
        'has_premium' => false,
        'image_url' => null,
        'mask_image_url' => null,
    ]);
}
