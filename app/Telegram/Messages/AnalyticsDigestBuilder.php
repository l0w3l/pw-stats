<?php

declare(strict_types=1);

namespace App\Telegram\Messages;

use App\Data\PixelWorld\Analytics\AnalyticsDigestData;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Queries\PeriodPlayerCountTrends;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
use Illuminate\Support\Facades\Log;
use Phptg\BotApi\Type\InputRichMessage;

class AnalyticsDigestBuilder
{
    public function __construct(
        private readonly PeriodPlayerCountTrends $trends,
        private readonly AnalyticsRichMessageFactory $messages,
        private readonly PlayerCountChartService $charts,
        private readonly AnalyticsChartMediaFactory $chartMedia,
    ) {}

    public function prepare(): AnalyticsDigestData
    {
        try {
            $chart = $this->charts->data();
        } catch (\Throwable) {
            $chart = null;
        }

        return new AnalyticsDigestData(
            new LeaderboardAnalyticsData($this->trends->get(), [], []),
            $chart,
        );
    }

    public function build(?string $locale = null, ?AnalyticsDigestData $data = null): InputRichMessage
    {
        $data ??= $this->prepare();
        $chart = null;

        if ($data->chart === null) {
            Log::warning('Analytics chart generation failed; sending digest without it.', [
                'locale' => $locale,
            ]);
        } else {
            try {
                $artifact = $this->charts->generateFromData($data->chart, $locale);
                $chart = $artifact === null ? null : $this->chartMedia->make($artifact, $locale);
            } catch (\Throwable) {
                Log::warning('Analytics chart generation failed; sending digest without it.', [
                    'locale' => $locale,
                ]);
            }
        }

        return $this->messages->make($data->analytics, $chart, $locale);
    }
}
