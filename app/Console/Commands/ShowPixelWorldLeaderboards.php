<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queries\LatestLeaderboardPeriods;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Illuminate\Console\Command;

class ShowPixelWorldLeaderboards extends Command
{
    protected $signature = 'pixel-world:leaderboards:show
                            {--range= : Show the latest period for day, week, or month}
                            {--limit=20 : Number of leaderboard entries to show}';

    protected $description = 'Show the latest successful Pixel World leaderboard periods';

    public function handle(LatestLeaderboardPeriods $periodsQuery): int
    {
        $range = $this->resolveRange();

        if ($range === false) {
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $periods = $periodsQuery->get($range);

        if ($periods->isEmpty()) {
            $this->warn('No successful leaderboard periods found.');

            return self::SUCCESS;
        }

        foreach ($periods as $period) {
            $this->newLine();
            $this->line(sprintf(
                '<info>%s</info> period #%d (%s — %s), last collected %s, %d/%d players%s',
                $period->range,
                $period->id,
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
                $period->last_collected_at->toDateTimeString(),
                $period->entries_count,
                $period->total,
                $period->is_partial ? " ({$period->missing_places} places missing)" : '',
            ));

            $entries = $period->entries()
                ->orderBy('place')
                ->limit($limit)
                ->get();

            $this->table(
                ['Place', 'Nickname', 'Level', 'Premium', 'Kills', 'UUID'],
                $entries->map(fn ($entry): array => [
                    $entry->place,
                    $entry->nickname,
                    $entry->level,
                    $entry->has_premium ? 'yes' : 'no',
                    $entry->points,
                    $entry->player_uuid,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function resolveRange(): LeaderboardRange|false|null
    {
        $value = $this->option('range');

        if ($value === null) {
            return null;
        }

        $range = LeaderboardRange::tryFrom($value);

        if ($range === null) {
            $this->error('The --range option must be day, week, or month.');

            return false;
        }

        return $range;
    }
}
