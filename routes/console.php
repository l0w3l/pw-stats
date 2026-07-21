<?php

use App\Jobs\SyncPixelWorldPlayerLeaderboards;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

foreach (LeaderboardRange::cases() as $range) {
    Schedule::job(new SyncPixelWorldPlayerLeaderboards($range))
        ->name("pixel-world:leaderboard:{$range->value}")
        ->everyTenMinutes();
}

Schedule::command('pixel-world:player-totals:collect')
    ->name('pixel-world:player-totals:collect')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->onOneServer();

Schedule::command('telegram:notifications:send')
    ->name('telegram:notifications:send')
    ->everyMinute()
    ->withoutOverlapping(60);
