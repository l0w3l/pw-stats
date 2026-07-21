<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Data\PixelWorld\Leaderboard\CalendarPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class LeaderboardCalendarPeriodResolver
{
    public function resolve(LeaderboardRange $range, ?DateTimeInterface $at = null): CalendarPeriod
    {
        $date = $at === null
            ? CarbonImmutable::now((string) config('app.timezone'))
            : CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'));

        [$start, $end] = match ($range) {
            LeaderboardRange::Day => [$date->startOfDay(), $date->endOfDay()],
            LeaderboardRange::Week => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)],
            LeaderboardRange::Month => [$date->startOfMonth(), $date->endOfMonth()],
        };

        return new CalendarPeriod($start, $end);
    }
}
