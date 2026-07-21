<?php

use App\Services\PixelWorld\Leaderboard\LeaderboardHistoryRetention;
use App\Services\PixelWorld\Stats\PlayerTotalRetention;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('retention commands are registered with UTC single-server bounded-overlap schedules', function () {
    $events = collect(app(Schedule::class)->events());

    $playerTotals = $events->first(
        fn (Event $event): bool => str_contains((string) $event->command, 'pixel-world:player-totals:prune'),
    );
    $leaderboardHistory = $events->first(
        fn (Event $event): bool => str_contains((string) $event->command, 'pixel-world:leaderboards:prune'),
    );

    expect($playerTotals)->toBeInstanceOf(Event::class)
        ->and($playerTotals->expression)->toBe('7 * * * *')
        ->and($playerTotals->timezone)->toBe('UTC')
        ->and($playerTotals->onOneServer)->toBeTrue()
        ->and($playerTotals->withoutOverlapping)->toBeTrue()
        ->and($playerTotals->expiresAt)->toBe(30)
        ->and($leaderboardHistory)->toBeInstanceOf(Event::class)
        ->and($leaderboardHistory->expression)->toBe('37 2 * * *')
        ->and($leaderboardHistory->timezone)->toBe('UTC')
        ->and($leaderboardHistory->onOneServer)->toBeTrue()
        ->and($leaderboardHistory->withoutOverlapping)->toBeTrue()
        ->and($leaderboardHistory->expiresAt)->toBe(60);
});

test('retention commands report candidates and affected rows on success', function () {
    $playerTotals = Mockery::mock(PlayerTotalRetention::class);
    $playerTotals->shouldReceive('run')->once()->with(null, false)->andReturn([
        'raw_rows_candidates' => 12,
        'hourly_rows_candidates' => 3,
        'hours_rolled_up' => 2,
        'raw_rows_deleted' => 8,
        'hourly_rows_deleted' => 3,
        'dry_run' => false,
    ]);
    app()->instance(PlayerTotalRetention::class, $playerTotals);

    $this->artisan('pixel-world:player-totals:prune')
        ->expectsOutput('Candidates: 12 raw rows and 3 expired hourly rows; rolled up 2 UTC hours and affected 11 rows (8 raw, 3 hourly).')
        ->assertSuccessful();

    $leaderboardHistory = Mockery::mock(LeaderboardHistoryRetention::class);
    $leaderboardHistory->shouldReceive('run')->once()->with(false)->andReturn([
        'expired_periods' => 4,
        'expired_entries' => 20,
        'selected_entries' => 10,
        'deleted_entries' => 10,
        'dry_run' => false,
    ]);
    app()->instance(LeaderboardHistoryRetention::class, $leaderboardHistory);

    $this->artisan('pixel-world:leaderboards:prune')
        ->expectsOutput('Candidates: 20 entries across 4 expired periods; selected 10 and affected 10 entries.')
        ->assertSuccessful();
});

test('retention commands return failure when their services fail', function () {
    $playerTotals = Mockery::mock(PlayerTotalRetention::class);
    $playerTotals->shouldReceive('run')->once()->andThrow(new RuntimeException('total failure'));
    app()->instance(PlayerTotalRetention::class, $playerTotals);

    $this->artisan('pixel-world:player-totals:prune')
        ->expectsOutput('Player-total retention failed: total failure')
        ->assertFailed();

    $leaderboardHistory = Mockery::mock(LeaderboardHistoryRetention::class);
    $leaderboardHistory->shouldReceive('run')->once()->andThrow(new RuntimeException('history failure'));
    app()->instance(LeaderboardHistoryRetention::class, $leaderboardHistory);

    $this->artisan('pixel-world:leaderboards:prune')
        ->expectsOutput('Leaderboard-history retention failed: history failure')
        ->assertFailed();
});

test('retention dry runs pass through without affected rows', function () {
    $playerTotals = Mockery::mock(PlayerTotalRetention::class);
    $playerTotals->shouldReceive('run')->once()->with(null, true)->andReturn([
        'raw_rows_candidates' => 12,
        'hourly_rows_candidates' => 3,
        'hours_rolled_up' => 0,
        'raw_rows_deleted' => 0,
        'hourly_rows_deleted' => 0,
        'dry_run' => true,
    ]);
    app()->instance(PlayerTotalRetention::class, $playerTotals);

    $this->artisan('pixel-world:player-totals:prune --dry-run')
        ->expectsOutput('Dry-run candidates: 12 raw rows and 3 expired hourly rows; rolled up 0 UTC hours and affected 0 rows (0 raw, 0 hourly).')
        ->assertSuccessful();

    $leaderboardHistory = Mockery::mock(LeaderboardHistoryRetention::class);
    $leaderboardHistory->shouldReceive('run')->once()->with(true)->andReturn([
        'expired_periods' => 4,
        'expired_entries' => 20,
        'selected_entries' => 10,
        'deleted_entries' => 0,
        'dry_run' => true,
    ]);
    app()->instance(LeaderboardHistoryRetention::class, $leaderboardHistory);

    $this->artisan('pixel-world:leaderboards:prune --dry-run')
        ->expectsOutput('Dry-run candidates: 20 entries across 4 expired periods; selected 10 and affected 0 entries.')
        ->assertSuccessful();
});
