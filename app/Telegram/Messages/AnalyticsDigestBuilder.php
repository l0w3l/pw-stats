<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\AnalyticsDigestData;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Queries\CurrentPointsThresholdAnalytics;
use App\Queries\PeriodPlayerCountTrends;
use Phptg\BotApi\Type\InputRichMessage;

class AnalyticsDigestBuilder
{
    public function __construct(
        private readonly PeriodPlayerCountTrends $trends,
        private readonly CurrentPointsThresholdAnalytics $thresholds,
        private readonly AnalyticsRichMessageFactory $messages,
    ) {}

    public function prepare(): AnalyticsDigestData
    {
        return new AnalyticsDigestData(
            new LeaderboardAnalyticsData(
                playerCountTrends: $this->trends->get(),
                pointsThresholds: $this->thresholds->get(),
                mostActivePlayers: [],
                momentumPlayers: [],
            ),
        );
    }

    public function build(?string $locale = null, ?AnalyticsDigestData $data = null): InputRichMessage
    {
        $data ??= $this->prepare();

        return $this->messages->make($data->analytics, locale: $locale);
    }
}
