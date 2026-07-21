<?php

use App\Models\PixelWorldPlayerTotal;
use App\Models\PixelWorldPlayerTotalHourly;
use App\Services\PixelWorld\Stats\PlayerTotalRetention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('pixel_world_retention.player_totals_raw_days', 1);
    config()->set('pixel_world_retention.player_totals_hourly_days', 10);
    config()->set('pixel_world_retention.player_totals_hours_per_run', 1);
});

test('it rolls up complete UTC hours before deleting raw samples in bounded batches', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:45:00', 'UTC');
    seedPlayerTotals([
        ['day', 10, '2026-07-20 10:00:00'],
        ['day', 20, '2026-07-20 10:30:00'],
        ['day', 30, '2026-07-20 10:59:00'],
        ['week', 100, '2026-07-20 10:10:00'],
        ['day', 40, '2026-07-20 11:30:00'],
        ['day', 50, '2026-07-20 12:00:00'],
    ]);

    $first = app(PlayerTotalRetention::class)->run($now);

    expect($first)->toBe([
        'raw_rows_candidates' => 5,
        'hourly_rows_candidates' => 0,
        'hours_rolled_up' => 1,
        'raw_rows_deleted' => 4,
        'hourly_rows_deleted' => 0,
        'dry_run' => false,
    ])->and(PixelWorldPlayerTotal::query()->count())->toBe(2);

    $day = PixelWorldPlayerTotalHourly::query()->where('range', 'day')->sole();
    expect($day->hour_at->toDateTimeString())->toBe('2026-07-20 10:00:00')
        ->and($day->sample_count)->toBe(3)
        ->and($day->minimum_total)->toBe(10)
        ->and($day->maximum_total)->toBe(30)
        ->and($day->average_total)->toBe('20.00')
        ->and($day->first_total)->toBe(10)
        ->and($day->last_total)->toBe(30);

    $second = app(PlayerTotalRetention::class)->run($now);
    $third = app(PlayerTotalRetention::class)->run($now);

    expect($second['raw_rows_deleted'])->toBe(1)
        ->and($third['raw_rows_deleted'])->toBe(0)
        ->and(PixelWorldPlayerTotal::query()->sole()->total)->toBe(50)
        ->and(PixelWorldPlayerTotalHourly::query()->count())->toBe(3);
});

test('it prunes hourly history in a bounded idempotent batch', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:45:00', 'UTC');

    foreach (['day', 'week', 'month', 'other'] as $index => $range) {
        PixelWorldPlayerTotalHourly::query()->create([
            'range' => $range,
            'hour_at' => $now->subDays(11)->addHours($index),
            'sample_count' => 1,
            'minimum_total' => 10,
            'maximum_total' => 10,
            'average_total' => 10,
            'first_total' => 10,
            'last_total' => 10,
        ]);
    }

    $first = app(PlayerTotalRetention::class)->run($now);
    $second = app(PlayerTotalRetention::class)->run($now);
    $third = app(PlayerTotalRetention::class)->run($now);

    expect($first['hourly_rows_deleted'])->toBe(3)
        ->and($second['hourly_rows_deleted'])->toBe(1)
        ->and($third['hourly_rows_deleted'])->toBe(0)
        ->and(PixelWorldPlayerTotalHourly::query()->count())->toBe(0);
});

test('a player-total dry run reports candidates without mutating raw or hourly data', function () {
    $now = CarbonImmutable::parse('2026-07-21 12:45:00', 'UTC');
    seedPlayerTotals([
        ['day', 10, '2026-07-20 10:00:00'],
        ['week', 20, '2026-07-21 12:00:00'],
    ]);
    PixelWorldPlayerTotalHourly::query()->create([
        'range' => 'day',
        'hour_at' => $now->subDays(11),
        'sample_count' => 1,
        'minimum_total' => 10,
        'maximum_total' => 10,
        'average_total' => 10,
        'first_total' => 10,
        'last_total' => 10,
    ]);

    $result = app(PlayerTotalRetention::class)->run($now, dryRun: true);

    expect($result)->toBe([
        'raw_rows_candidates' => 1,
        'hourly_rows_candidates' => 1,
        'hours_rolled_up' => 0,
        'raw_rows_deleted' => 0,
        'hourly_rows_deleted' => 0,
        'dry_run' => true,
    ])->and(PixelWorldPlayerTotal::query()->count())->toBe(2)
        ->and(PixelWorldPlayerTotalHourly::query()->count())->toBe(1);
});

/** @param array<int, array{string, int, string}> $rows */
function seedPlayerTotals(array $rows): void
{
    foreach ($rows as [$range, $total, $collectedAt]) {
        PixelWorldPlayerTotal::query()->create([
            'range' => $range,
            'total' => $total,
            'collected_at' => CarbonImmutable::parse($collectedAt, 'UTC'),
        ]);
    }
}
