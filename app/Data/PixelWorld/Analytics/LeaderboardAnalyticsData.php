<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

use Carbon\CarbonImmutable;

final readonly class LeaderboardAnalyticsData
{
    /**
     * @param  array<PlayerCountTrendData>  $playerCountTrends
     * @param  array<PointsThresholdData>  $pointsThresholds
     * @param  array<PlayerActivityData>  $mostActivePlayers
     * @param  array<PlayerMomentumData>  $momentumPlayers
     */
    public function __construct(
        public array $playerCountTrends,
        public array $pointsThresholds,
        public array $mostActivePlayers,
        public array $momentumPlayers,
        public ?CarbonImmutable $latestCollectedAt = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->playerCountTrends === [] && $this->pointsThresholds === [];
    }
}
