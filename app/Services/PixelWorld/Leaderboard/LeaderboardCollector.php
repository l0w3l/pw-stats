<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Data\PixelWorld\Stats\PlayersLeaderboardResponseData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class LeaderboardCollector
{
    public function __construct(
        private readonly LeaderboardClient $client,
        private readonly LeaderboardPeriodWriter $writer,
        private readonly LeaderboardCalendarPeriodResolver $periodResolver = new LeaderboardCalendarPeriodResolver,
    ) {}

    /** @param null|callable(string, int, int): void $onProgress */
    public function collect(LeaderboardRange $range, ?callable $onProgress = null): ?PixelWorldLeaderboardPeriod
    {
        $lock = Cache::lock(
            "pixel-world:leaderboard:collect:{$range->value}",
            max(60, (int) config('services.pixel-world.collection_lock_ttl_seconds')),
        );

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->collectLocked($range, $onProgress);
        } finally {
            $lock->release();
        }
    }

    private function collectLocked(LeaderboardRange $range, ?callable $onProgress): PixelWorldLeaderboardPeriod
    {
        $calendarPeriod = $this->periodResolver->resolve($range);
        $limit = max(1, (int) config('services.pixel-world.leaderboard_page_limit'));
        $firstPage = $this->client->page($range, 1, $limit);
        $leaderboard = $firstPage->data->leaderboard;
        $this->assertPage($firstPage, $range, 1);

        $lastPage = max(1, (int) ceil($leaderboard->list->total / $limit));
        $buffer = new LeaderboardCollectionBuffer($leaderboard->list->total);

        if ($onProgress) {
            $onProgress('scan', 0, $lastPage);
        }

        for ($page = $lastPage; $page >= 1; $page--) {
            $response = $lastPage === 1 ? $firstPage : $this->client->page($range, $page, $limit);
            $this->assertPage($response, $range, $page);
            $buffer->append($response->data->leaderboard->list->players);

            if ($page === 1) {
                $buffer->synchronizeTotal($response->data->leaderboard->list->total);
            }

            if ($onProgress) {
                $onProgress('scan', $lastPage - $page + 1, $lastPage);
            }
        }

        $this->reconcile($buffer, $range, $limit, $onProgress);
        $collectedAt = CarbonImmutable::now((string) config('app.timezone'));
        $periodAtCompletion = $this->periodResolver->resolve($range, $collectedAt);

        if (! $calendarPeriod->start->isSameDay($periodAtCompletion->start)
            || ! $calendarPeriod->end->isSameDay($periodAtCompletion->end)) {
            throw new \RuntimeException("The {$range->value} calendar period changed during collection; retrying is required.");
        }

        return $this->writer->replace(
            $range,
            $calendarPeriod,
            $leaderboard->user,
            $buffer->players(),
            $buffer->total(),
            $collectedAt,
        );
    }

    /** @param null|callable(string, int, int): void $onProgress */
    private function reconcile(
        LeaderboardCollectionBuffer $buffer,
        LeaderboardRange $range,
        int $limit,
        ?callable $onProgress,
    ): void {
        $maxPasses = max(0, (int) config('services.pixel-world.reconciliation_passes'));

        for ($pass = 1; $pass <= $maxPasses; $pass++) {
            if ($buffer->missingPlaces() === []) {
                return;
            }

            // Refresh page one first: it is both the most volatile page and the source of the current total.
            $head = $this->client->page($range, 1, $limit);
            $this->assertPage($head, $range, 1);
            $buffer->synchronizeTotal($head->data->leaderboard->list->total);

            $pages = [];

            foreach ($buffer->missingPlaces() as $place) {
                $pages[(int) ceil($place / $limit)] = true;
            }

            unset($pages[1]);
            $pages = array_keys($pages);
            rsort($pages);
            $phase = "reconcile-{$pass}";
            $pagesTotal = count($pages) + 1;

            if ($onProgress) {
                $onProgress($phase, 0, $pagesTotal);
            }

            foreach ($pages as $index => $page) {
                $response = $this->client->page($range, $page, $limit);
                $this->assertPage($response, $range, $page);
                $buffer->append($response->data->leaderboard->list->players);

                if ($onProgress) {
                    $onProgress($phase, $index + 1, $pagesTotal);
                }
            }

            // Page one is persisted last so a player's better rank wins any cross-page race.
            $buffer->append($head->data->leaderboard->list->players);

            if ($onProgress) {
                $onProgress($phase, $pagesTotal, $pagesTotal);
            }
        }
    }

    private function assertPage(
        PlayersLeaderboardResponseData $response,
        LeaderboardRange $range,
        int $page,
    ): void {
        $leaderboard = $response->data->leaderboard;

        if (! $response->ok || $leaderboard->range !== $range->value || $leaderboard->list->page !== $page) {
            throw new \RuntimeException(sprintf(
                'Invalid %s leaderboard response: requested page %d, received range %s and page %d.',
                $range->value,
                $page,
                $leaderboard->range,
                $leaderboard->list->page,
            ));
        }
    }
}
