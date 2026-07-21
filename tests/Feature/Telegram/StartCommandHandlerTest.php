<?php

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Queries\LeaderboardAnalytics;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
use App\Telegram\Messages\AnalyticsChartMediaFactory;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use App\Telegram\Messages\AnalyticsRichMessageFactory;

test('scheduled digest still sends analytics when chart generation fails', function () {
    $analytics = Mockery::mock(LeaderboardAnalytics::class);
    $analytics->shouldReceive('get')->once()->andReturn(new LeaderboardAnalyticsData([], [], [], null));

    $charts = Mockery::mock(PlayerCountChartService::class);
    $charts->shouldReceive('generate')->once()->andThrow(new RuntimeException('Imagick unavailable'));

    $message = (new AnalyticsDigestBuilder(
        $analytics,
        new AnalyticsRichMessageFactory,
        $charts,
        new AnalyticsChartMediaFactory,
    ))->build();

    expect($message->blocks)->toHaveCount(2);
});
