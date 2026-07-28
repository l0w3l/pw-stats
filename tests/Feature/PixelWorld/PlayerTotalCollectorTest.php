<?php

use App\Data\PixelWorld\Stats\PlayersLeaderboardResponseData;
use App\Models\PixelWorldPlayerTotal;
use App\Services\PixelWorld\Leaderboard\LeaderboardClient;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use App\Services\PixelWorld\Stats\PlayerTotalCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

test('player totals are sampled once per range and UTC five second window', function () {
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
    $instant = CarbonImmutable::parse('2026-07-21 12:34:45.500000', 'UTC');

    $first = $collector->collect(LeaderboardRange::Day, $instant);
    $updated = $collector->collect(LeaderboardRange::Day, $instant->addSeconds(3));
    $next = $collector->collect(LeaderboardRange::Day, $instant->addSeconds(5));

    expect($first->id)->toBe($updated->id)
        ->and($updated->total)->toBe(101)
        ->and($updated->collected_at?->toIso8601String())->toBe('2026-07-21T12:34:45+00:00')
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
        ->toBe('2026-07-21 12:34:45');

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

test('player total command is scheduled every five seconds', function () {
    config()->set('cache.default', 'array');

    $event = collect(Schedule::events())
        ->first(fn ($event): bool => $event->description === 'pixel-world:player-totals:collect');

    expect($event)->not->toBeNull()
        ->and($event->repeatSeconds)->toBe(5)
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('full leaderboard collection uses staggered range schedules', function () {
    $expressions = collect(Schedule::events())
        ->filter(fn ($event): bool => str_starts_with((string) $event->description, 'pixel-world:leaderboard:'))
        ->mapWithKeys(fn ($event): array => [$event->description => $event->expression]);

    expect($expressions->all())->toBe([
        'pixel-world:leaderboard:day' => '*/30 * * * *',
        'pixel-world:leaderboard:week' => '17 * * * *',
        'pixel-world:leaderboard:month' => '37 */2 * * *',
    ]);
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
