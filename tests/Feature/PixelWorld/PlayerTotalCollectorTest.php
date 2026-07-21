<?php

use App\Data\PixelWorld\Stats\PlayersLeaderboardResponseData;
use App\Models\PixelWorldPlayerTotal;
use App\Services\PixelWorld\Leaderboard\LeaderboardClient;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use App\Services\PixelWorld\Stats\PlayerTotalCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('player totals are sampled once per range and UTC minute', function () {
    $client = Mockery::mock(LeaderboardClient::class);
    $client->shouldReceive('page')
        ->times(3)
        ->with(LeaderboardRange::Day, 1, 1)
        ->andReturn(
            playerTotalResponse(100),
            playerTotalResponse(101),
            playerTotalResponse(102),
        );
    $collector = new PlayerTotalCollector($client);
    $minute = CarbonImmutable::parse('2026-07-21 12:34:45', 'UTC');

    $first = $collector->collect(LeaderboardRange::Day, $minute);
    $updated = $collector->collect(LeaderboardRange::Day, $minute->addSeconds(10));
    $next = $collector->collect(LeaderboardRange::Day, $minute->addMinute());

    expect($first->id)->toBe($updated->id)
        ->and($updated->total)->toBe(101)
        ->and($updated->collected_at?->toIso8601String())->toBe('2026-07-21T12:34:00+00:00')
        ->and($next->id)->not->toBe($first->id)
        ->and(PixelWorldPlayerTotal::query()->count())->toBe(2);
});

test('player total command persists day week and month in the same minute', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:34:45', 'UTC');
    CarbonImmutable::setTestNow($now);
    $client = Mockery::mock(LeaderboardClient::class);

    foreach ([[LeaderboardRange::Day, 10], [LeaderboardRange::Week, 20], [LeaderboardRange::Month, 30]] as [$range, $total]) {
        $client->shouldReceive('page')
            ->once()
            ->with($range, 1, 1)
            ->andReturn(playerTotalResponse($total));
    }

    app()->instance(LeaderboardClient::class, $client);

    $this->artisan('pixel-world:player-totals:collect')
        ->expectsOutputToContain('Collected totals: 3; failed ranges: 0.')
        ->assertSuccessful();

    expect(PixelWorldPlayerTotal::query()->orderBy('range')->pluck('total', 'range')->all())
        ->toBe(['day' => 10, 'month' => 30, 'week' => 20])
        ->and(PixelWorldPlayerTotal::query()->pluck('collected_at')->unique()->sole()->toDateTimeString())
        ->toBe('2026-07-21 12:34:00');

    CarbonImmutable::setTestNow();
});

test('player total command collects every range and continues after one failure', function () {
    Log::spy();
    $collector = Mockery::mock(PlayerTotalCollector::class);
    $collector->shouldReceive('collect')->once()->with(LeaderboardRange::Day, Mockery::type(CarbonImmutable::class))
        ->andReturn(new PixelWorldPlayerTotal(['total' => 10]));
    $collector->shouldReceive('collect')->once()->with(LeaderboardRange::Week, Mockery::type(CarbonImmutable::class))
        ->andThrow(new RuntimeException('API unavailable'));
    $collector->shouldReceive('collect')->once()->with(LeaderboardRange::Month, Mockery::type(CarbonImmutable::class))
        ->andReturn(new PixelWorldPlayerTotal(['total' => 30]));
    app()->instance(PlayerTotalCollector::class, $collector);

    $this->artisan('pixel-world:player-totals:collect')
        ->expectsOutputToContain('day: 10')
        ->expectsOutputToContain('Failed to collect week: API unavailable')
        ->expectsOutputToContain('month: 30')
        ->expectsOutputToContain('Collected totals: 2; failed ranges: 1.')
        ->assertFailed();
});

test('player total command is scheduled every minute', function () {
    config()->set('cache.default', 'array');

    $this->artisan('schedule:list')
        ->expectsOutputToContain('pixel-world:player-totals:collect')
        ->assertSuccessful();
});

function playerTotalResponse(int $total): PlayersLeaderboardResponseData
{
    return PlayersLeaderboardResponseData::from([
        'ok' => true,
        'data' => [
            'leaderboard' => [
                'range' => 'day',
                'user' => playerTotalPlayer('viewer', 10),
                'list' => [
                    'page' => 1,
                    'total' => $total,
                    'players' => [playerTotalPlayer('first', 1)],
                ],
            ],
        ],
    ]);
}

function playerTotalPlayer(string $uuid, int $place): array
{
    return [
        'uuid' => $uuid,
        'image_url' => 'https://example.test/avatar.png',
        'mask_image_url' => null,
        'level' => 1,
        'nickname' => $uuid,
        'has_premium' => false,
        'place' => $place,
        'points' => 0,
    ];
}
