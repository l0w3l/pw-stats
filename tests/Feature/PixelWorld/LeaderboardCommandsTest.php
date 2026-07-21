<?php

use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldLeaderboardPeriodEntry;
use App\Models\PixelWorldPlayer;
use App\Queries\LatestLeaderboardPeriods;
use App\Services\PixelWorld\Leaderboard\LeaderboardCollector;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the collection command reports period progress for every range', function () {
    $periodId = 0;
    $collector = Mockery::mock(LeaderboardCollector::class);
    $collector->shouldReceive('collect')->times(3)->andReturnUsing(function ($range, callable $progress) use (&$periodId): PixelWorldLeaderboardPeriod {
        $progress('scan', 0, 1);
        $progress('scan', 1, 1);
        $period = new PixelWorldLeaderboardPeriod(['range' => $range->value, 'entries_count' => 1]);
        $period->id = ++$periodId;

        return $period;
    });
    app()->instance(LeaderboardCollector::class, $collector);

    $this->artisan('pixel-world:leaderboards:collect')
        ->expectsOutputToContain('Period #1 updated with 1 entries.')
        ->expectsOutputToContain('Updated periods: 3; failed ranges: 0.')
        ->assertSuccessful();
});

test('the display command renders historical entry data from the latest period', function () {
    $player = PixelWorldPlayer::create([
        'uuid' => 'player-uuid', 'image_url' => null, 'mask_image_url' => null,
        'level' => 99, 'nickname' => 'Current Name', 'has_premium' => false,
    ]);
    $period = commandPeriod('2026-07-20', now());
    PixelWorldLeaderboardPeriodEntry::create([
        'period_id' => $period->id, 'player_uuid' => $player->uuid, 'place' => 1, 'points' => 100,
        'nickname' => 'Historical Name', 'level' => 42, 'has_premium' => true,
        'image_url' => null, 'mask_image_url' => null,
    ]);

    $this->artisan('pixel-world:leaderboards:show', ['--range' => 'day'])
        ->expectsOutputToContain('period #')
        ->expectsOutputToContain('Historical Name')
        ->doesntExpectOutputToContain('Current Name')
        ->assertSuccessful();
});

test('the latest period query chooses the newest calendar period', function () {
    $older = commandPeriod('2026-07-19', now()->addHour());
    $newer = commandPeriod('2026-07-20', now());
    $latest = (new LatestLeaderboardPeriods)->get(LeaderboardRange::Day)->sole();

    expect($latest->id)->toBe($newer->id)->and($latest->id)->not->toBe($older->id);
});

function commandPeriod(string $date, DateTimeInterface $collectedAt): PixelWorldLeaderboardPeriod
{
    return PixelWorldLeaderboardPeriod::create([
        'range' => 'day', 'period_start' => $date, 'period_end' => $date,
        'total' => 1, 'entries_count' => 1, 'missing_places' => 0, 'is_partial' => false,
        'last_collected_at' => $collectedAt,
    ]);
}
