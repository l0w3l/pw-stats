<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PixelWorld\Leaderboard\LeaderboardCollector;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncPixelWorldPlayerLeaderboards implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 6900;

    public int $backoff = 60;

    public int $uniqueFor = 7500;

    public function __construct(public readonly LeaderboardRange $range) {}

    public function handle(LeaderboardCollector $collector): void
    {
        if ($collector->collect($this->range) === null) {
            $this->release(60);
        }
    }

    public function uniqueId(): string
    {
        return $this->range->value;
    }
}
