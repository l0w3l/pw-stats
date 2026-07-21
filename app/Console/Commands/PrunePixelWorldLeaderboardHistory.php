<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PixelWorld\Leaderboard\LeaderboardHistoryRetention;
use Illuminate\Console\Command;
use Throwable;

class PrunePixelWorldLeaderboardHistory extends Command
{
    protected $signature = 'pixel-world:leaderboards:prune {--dry-run : Report eligible details without deleting them}';

    protected $description = 'Prune bounded batches of expired leaderboard entry details while retaining period history';

    public function handle(LeaderboardHistoryRetention $retention): int
    {
        try {
            $result = $retention->run((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Leaderboard-history retention failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: %d entries across %d expired periods; selected %d and affected %d entries.',
            $result['dry_run'] ? 'Dry-run candidates' : 'Candidates',
            $result['expired_entries'],
            $result['expired_periods'],
            $result['selected_entries'],
            $result['deleted_entries'],
        ));

        return self::SUCCESS;
    }
}
