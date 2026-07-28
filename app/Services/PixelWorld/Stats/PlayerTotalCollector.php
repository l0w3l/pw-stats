<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Stats;

use App\Models\PixelWorldPlayerTotal;
use App\Services\PixelWorld\Leaderboard\LeaderboardClient;
use Carbon\CarbonImmutable;

class PlayerTotalCollector
{
    public function __construct(private readonly LeaderboardClient $leaderboards) {}

    public function collect(
        LeaderboardRange $range,
        ?CarbonImmutable $collectedAt = null,
    ): PixelWorldPlayerTotal {
        $collectedAt = ($collectedAt ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
        $collectedAt = $collectedAt->setSecond(intdiv($collectedAt->second, 5) * 5);
        $response = $this->leaderboards->page($range, page: 1, limit: 1);
        $writtenAt = CarbonImmutable::now('UTC');

        PixelWorldPlayerTotal::query()->upsert(
            [[
                'range' => $range->value,
                'total' => $response->data->leaderboard->list->total,
                'collected_at' => $collectedAt,
                'created_at' => $writtenAt,
                'updated_at' => $writtenAt,
            ]],
            ['range', 'collected_at'],
            ['total', 'updated_at'],
        );

        return PixelWorldPlayerTotal::query()
            ->where('range', $range->value)
            ->where('collected_at', $collectedAt)
            ->firstOrFail();
    }
}
