<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PixelWorld\Stats\PlayerTotalRetention;
use Illuminate\Console\Command;
use Throwable;

class PrunePixelWorldPlayerTotals extends Command
{
    protected $signature = 'pixel-world:player-totals:prune {--dry-run : Report eligible rows without rolling up or deleting them}';

    protected $description = 'Roll up expired minute player totals and prune bounded retention batches';

    public function handle(PlayerTotalRetention $retention): int
    {
        try {
            $result = $retention->run(null, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Player-total retention failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: %d raw rows and %d expired hourly rows; rolled up %d UTC hours and affected %d rows (%d raw, %d hourly).',
            $result['dry_run'] ? 'Dry-run candidates' : 'Candidates',
            $result['raw_rows_candidates'],
            $result['hourly_rows_candidates'],
            $result['hours_rolled_up'],
            $result['raw_rows_deleted'] + $result['hourly_rows_deleted'],
            $result['raw_rows_deleted'],
            $result['hourly_rows_deleted'],
        ));

        return self::SUCCESS;
    }
}
