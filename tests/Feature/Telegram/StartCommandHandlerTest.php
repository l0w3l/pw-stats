<?php

use App\Data\PixelWorld\Analytics\PlayerCountChartData;
use App\Queries\PeriodPlayerCountTrends;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
use App\Telegram\Messages\AnalyticsChartMediaFactory;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use App\Telegram\Messages\AnalyticsRichMessageFactory;

test('scheduled digest still sends analytics when chart generation fails', function () {
    $trends = Mockery::mock(PeriodPlayerCountTrends::class);
    $trends->shouldReceive('get')->once()->andReturn([]);

    $charts = Mockery::mock(PlayerCountChartService::class);
    $chartData = new PlayerCountChartData([], []);
    $charts->shouldReceive('data')->once()->andReturn($chartData);
    $charts->shouldReceive('generateFromData')->once()->andThrow(new RuntimeException('Imagick unavailable'));

    $message = (new AnalyticsDigestBuilder(
        $trends,
        new AnalyticsRichMessageFactory,
        $charts,
        new AnalyticsChartMediaFactory,
    ))->build();

    expect($message->blocks)->toHaveCount(2);
});
