<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PixelWorld\Leaderboard\LeaderboardCollector;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Console\Command;

class CollectPixelWorldLeaderboards extends Command
{
    protected $signature = 'pixel-world:leaderboards:collect';

    protected $description = 'Update the current Pixel World calendar leaderboard periods';

    public function handle(LeaderboardCollector $collector): int
    {
        $completed = 0;
        $failed = 0;

        foreach (LeaderboardRange::cases() as $range) {
            $this->newLine();
            $this->line("Collecting <info>{$range->value}</info> leaderboard metadata...");
            $progressBars = [];

            try {
                $period = $collector->collect($range, function (string $phase, int $processed, int $total) use (&$progressBars): void {
                    if ($total === 0) {
                        return;
                    }

                    if (! isset($progressBars[$phase])) {
                        if ($phase !== 'scan') {
                            $pass = str_replace('reconcile-', '', $phase);
                            $this->line("Reconciling missing places, pass {$pass} ({$total} pages)...");
                        }

                        $progressBars[$phase] = $this->output->createProgressBar($total);
                        $progressBars[$phase]->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
                        $progressBars[$phase]->start();
                    }

                    if ($processed > 0) {
                        $progressBars[$phase]->advance();
                    }

                    if ($processed === $total) {
                        $progressBars[$phase]->finish();
                        $this->newLine();
                    }
                });

                if ($period === null) {
                    $this->warn("The {$range->value} leaderboard is already being collected.");

                    continue;
                }

                $suffix = $period->is_partial ? " ({$period->missing_places} places still missing)" : '';
                $this->info("Period #{$period->id} updated with {$period->entries_count} entries{$suffix}.");
                $completed++;
            } catch (\Throwable $exception) {
                $this->newLine();
                $this->error("Failed to collect {$range->value}: {$exception->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Updated periods: {$completed}; failed ranges: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
