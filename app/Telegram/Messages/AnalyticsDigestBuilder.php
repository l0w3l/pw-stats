<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Queries\LeaderboardAnalytics;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
use Illuminate\Support\Facades\Log;
use Phptg\BotApi\Type\InputRichMessage;

class AnalyticsDigestBuilder
{
    public function __construct(
        private readonly LeaderboardAnalytics $analytics,
        private readonly AnalyticsRichMessageFactory $messages,
        private readonly PlayerCountChartService $charts,
        private readonly AnalyticsChartMediaFactory $chartMedia,
    ) {}

    public function build(): InputRichMessage
    {
        $chart = null;

        try {
            $artifact = $this->charts->generate();
            $chart = $artifact === null ? null : $this->chartMedia->make($artifact);
        } catch (\Throwable $exception) {
            Log::warning('Analytics chart generation failed; sending digest without it.', [
                'exception' => $exception,
            ]);
        }

        return $this->messages->make($this->analytics->get(), $chart);
    }
}
