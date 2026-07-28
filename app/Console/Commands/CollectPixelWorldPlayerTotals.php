<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PixelWorld\Stats\LeaderboardRange;
use App\Services\PixelWorld\Stats\PlayerTotalCollector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CollectPixelWorldPlayerTotals extends Command
{
    protected $signature = 'pixel-world:player-totals:collect';

    protected $description = 'Collect Pixel World player totals for every leaderboard range';

    public function handle(PlayerTotalCollector $collector): int
    {
        $collectedAt = CarbonImmutable::now('UTC');
        $completed = 0;
        $failed = 0;

        foreach (LeaderboardRange::cases() as $range) {
            try {
                $sample = $collector->collect($range, $collectedAt);
                $this->info("{$range->value}: {$sample->total}");
                $completed++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->error("Failed to collect {$range->value}: {$exception->getMessage()}");
                $failed++;
            }
        }

        $this->line("Collected totals: {$completed}; failed ranges: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
