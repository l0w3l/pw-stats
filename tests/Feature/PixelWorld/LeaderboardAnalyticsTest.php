<?php

use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldLeaderboardPeriodEntry;
use App\Models\PixelWorldPlayer;
use App\Queries\LeaderboardAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('it compares calendar periods and calculates kills per day and day momentum', function () {
    $collectedAt = now();
    $dayPrevious = analyticsPeriod('day', '2026-07-19', '2026-07-19', 100, $collectedAt->copy()->subMinutes(10));
    $dayCurrent = analyticsPeriod('day', '2026-07-20', '2026-07-20', 105, $collectedAt);
    analyticsEntry($dayPrevious, 'runner', 10, 10);
    analyticsEntry($dayCurrent, 'runner', 7, 15);

    $week = analyticsPeriod('week', '2026-07-20', '2026-07-26', 500, $collectedAt);
    analyticsEntry($week, 'week-leader', 1, 70);
    analyticsEntry($week, 'week-second', 2, 35);

    $month = analyticsPeriod('month', '2026-07-01', '2026-07-31', 1000, $collectedAt);
    analyticsEntry($month, 'month-leader', 1, 300);

    $analytics = (new LeaderboardAnalytics)->get();
    $dayTrend = collect($analytics->playerCountTrends)->firstWhere('range', 'day');
    $weekLeader = collect($analytics->mostActivePlayers)->firstWhere('nickname', 'week-leader');

    expect($dayTrend->current)->toBe(105)
        ->and($dayTrend->delta)->toBe(5)
        ->and($weekLeader->killsPerDay)->toBe(10.0)
        ->and(collect($analytics->mostActivePlayers)->firstWhere('nickname', 'month-leader')->killsPerDay)->toBe(10.0)
        ->and($analytics->momentumPlayers[0]->nickname)->toBe('runner')
        ->and($analytics->momentumPlayers[0]->killsDelta)->toBe(5)
        ->and($analytics->momentumPlayers[0]->rankDelta)->toBe(3)
        ->and($analytics->latestCollectedAt->toDateTimeString())->toBe($collectedAt->toDateTimeString());
});

test('day momentum uses matching players with positive deltas and applies deterministic top five ordering', function () {
    $previous = analyticsPeriod('day', '2026-07-19', '2026-07-19', 20, now()->subDay());
    $current = analyticsPeriod('day', '2026-07-20', '2026-07-20', 20, now());

    $players = [
        // Equal kills and rank deltas are resolved by player UUID.
        ['alpha', 10, 7, 20, 30],
        ['bravo', 11, 8, 15, 25],
        // Equal kills deltas are ordered by rank delta descending.
        ['charlie', 12, 4, 40, 50],
        ['delta', 13, 12, 50, 59],
        ['echo', 14, 13, 60, 68],
        ['foxtrot', 15, 14, 70, 77],
    ];

    foreach ($players as [$uuid, $previousPlace, $currentPlace, $previousKills, $currentKills]) {
        analyticsEntry($previous, $uuid, $previousPlace, $previousKills);
        analyticsEntry($current, $uuid, $currentPlace, $currentKills);
    }

    analyticsEntry($previous, 'unchanged', 16, 80);
    analyticsEntry($current, 'unchanged', 15, 80);
    analyticsEntry($previous, 'decreased', 17, 90);
    analyticsEntry($current, 'decreased', 16, 89);
    analyticsEntry($previous, 'missing-current', 18, 100);
    analyticsEntry($current, 'missing-previous', 17, 100);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $momentum = (new LeaderboardAnalytics)->get()->momentumPlayers;
    $entryQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'pixel_world_leaderboard_period_entries'));

    DB::disableQueryLog();

    expect(array_map(fn ($player) => $player->playerUuid, $momentum))
        ->toBe(['charlie', 'alpha', 'bravo', 'delta', 'echo'])
        ->and($momentum)->toHaveCount(5)
        ->and(collect($momentum)->pluck('playerUuid')->all())
        ->not->toContain('unchanged', 'decreased', 'missing-current', 'missing-previous', 'foxtrot')
        ->and($entryQueries)->toHaveCount(1)
        ->and($entryQueries->first()['query'])->toContain('inner join', 'limit 5');
});

function analyticsPeriod(
    string $range,
    string $start,
    string $end,
    int $total,
    DateTimeInterface $collectedAt,
): PixelWorldLeaderboardPeriod {
    return PixelWorldLeaderboardPeriod::create([
        'range' => $range,
        'period_start' => $start,
        'period_end' => $end,
        'total' => $total,
        'entries_count' => 0,
        'missing_places' => 0,
        'is_partial' => false,
        'last_collected_at' => $collectedAt,
    ]);
}

function analyticsEntry(PixelWorldLeaderboardPeriod $period, string $nickname, int $place, int $kills): void
{
    PixelWorldPlayer::query()->firstOrCreate(['uuid' => $nickname], [
        'image_url' => null,
        'mask_image_url' => null,
        'level' => 42,
        'nickname' => $nickname,
        'has_premium' => false,
    ]);

    PixelWorldLeaderboardPeriodEntry::create([
        'period_id' => $period->id,
        'player_uuid' => $nickname,
        'place' => $place,
        'points' => $kills,
        'nickname' => $nickname,
        'level' => 42,
        'has_premium' => false,
        'image_url' => null,
        'mask_image_url' => null,
    ]);
}
