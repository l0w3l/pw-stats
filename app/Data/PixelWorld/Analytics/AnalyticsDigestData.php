<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class AnalyticsDigestData
{
    public function __construct(
        public LeaderboardAnalyticsData $analytics,
        public ?int $monthlyKills = null,
    ) {}
}
