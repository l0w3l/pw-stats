<?php

use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PointsThresholdData;
use App\Queries\CurrentMonthKillTotal;
use App\Queries\CurrentPointsThresholdAnalytics;
use App\Queries\PeriodPlayerCountTrends;
use App\Telegram\Messages\AnalyticsDigestBuilder;
use App\Telegram\Messages\AnalyticsRichMessageFactory;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\InputRichBlockTable;

test('scheduled digest contains totals and points thresholds without a graph', function () {
    $trends = Mockery::mock(PeriodPlayerCountTrends::class);
    $trends->shouldReceive('get')->once()->andReturn([
        new PlayerCountTrendData('day', 100, 90, 10),
        new PlayerCountTrendData('month', 12, 10, 2),
    ]);
    $thresholds = Mockery::mock(CurrentPointsThresholdAnalytics::class);
    $thresholds->shouldReceive('get')->once()->andReturn([
        new PointsThresholdData('day', 20, 10, 2),
    ]);
    $monthlyKills = Mockery::mock(CurrentMonthKillTotal::class);
    $monthlyKills->shouldReceive('get')->once()->andReturn(366);

    $message = (new AnalyticsDigestBuilder(
        $trends,
        $thresholds,
        $monthlyKills,
        new AnalyticsRichMessageFactory,
    ))->build();
    $tables = collect($message->blocks)->filter(
        fn ($block): bool => $block instanceof InputRichBlockTable,
    );

    expect($message->blocks)->toHaveCount(4)
        ->and($tables)->toHaveCount(2)
        ->and(json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
        ->toContain('Убийств за месяц: 366 (≈31 на игрока или 2 мин 4 сек в игре)')
        ->and(collect($message->blocks)->contains(
            fn ($block): bool => $block instanceof InputRichBlockPhoto,
        ))->toBeFalse();
});
