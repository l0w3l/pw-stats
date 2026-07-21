<?php

use App\Services\PixelWorld\Leaderboard\LeaderboardCalendarPeriodResolver;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;

test('it resolves day week and month boundaries in the application timezone', function () {
    config()->set('app.timezone', 'Europe/Moscow');
    $resolver = new LeaderboardCalendarPeriodResolver;
    $instant = CarbonImmutable::parse('2026-01-01 00:30:00', 'Europe/Moscow');

    $day = $resolver->resolve(LeaderboardRange::Day, $instant);
    $week = $resolver->resolve(LeaderboardRange::Week, $instant);
    $month = $resolver->resolve(LeaderboardRange::Month, $instant);

    expect($day->start->toDateString())->toBe('2026-01-01')
        ->and($day->end->toDateString())->toBe('2026-01-01')
        ->and($week->start->toDateString())->toBe('2025-12-29')
        ->and($week->end->toDateString())->toBe('2026-01-04')
        ->and($month->start->toDateString())->toBe('2026-01-01')
        ->and($month->end->toDateString())->toBe('2026-01-31');
});

test('week rollover is ISO Monday through Sunday', function () {
    $resolver = new LeaderboardCalendarPeriodResolver;
    $sunday = $resolver->resolve(LeaderboardRange::Week, CarbonImmutable::parse('2026-07-19 23:59:59'));
    $monday = $resolver->resolve(LeaderboardRange::Week, CarbonImmutable::parse('2026-07-20 00:00:00'));

    expect($sunday->start->toDateString())->toBe('2026-07-13')
        ->and($sunday->end->toDateString())->toBe('2026-07-19')
        ->and($monday->start->toDateString())->toBe('2026-07-20')
        ->and($monday->end->toDateString())->toBe('2026-07-26');
});
