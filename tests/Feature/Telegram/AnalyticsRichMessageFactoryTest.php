<?php

use App\Data\PixelWorld\Analytics\ChartArtifact;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerActivityData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PlayerMomentumData;
use App\Telegram\Messages\AnalyticsChartMediaFactory;
use App\Telegram\Messages\AnalyticsRichMessageFactory;
use Carbon\CarbonImmutable;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\InputRichBlockTable;

test('it builds native rich tables for the analytics digest', function () {
    $analytics = new LeaderboardAnalyticsData(
        playerCountTrends: [new PlayerCountTrendData('day', 105, 100, 5)],
        mostActivePlayers: [new PlayerActivityData('week', 'uuid', 'Slayer', 70, 10.0)],
        momentumPlayers: [new PlayerMomentumData('uuid', 'Slayer', 5, 3)],
        latestCollectedAt: CarbonImmutable::parse('2026-07-16 12:30:00', 'UTC'),
    );

    $message = (new AnalyticsRichMessageFactory)->make($analytics);
    $tables = collect($message->blocks)->filter(fn ($block): bool => $block instanceof InputRichBlockTable)->values();

    expect($tables)->toHaveCount(3)
        ->and($tables[0]->cells[1][1]->text)->toBe('105')
        ->and($tables[0]->cells[1][2]->text)->toBe('+5')
        ->and($tables[1]->cells[1][1]->text)->toBe('Slayer')
        ->and($tables[1]->cells[1][2]->text)->toBe('10.0')
        ->and($tables[2]->cells[1][1]->text)->toBe('+5')
        ->and($tables[2]->cells[1][2]->text)->toBe('↑ 3')
        ->and(json_encode($message->toRequestArray()))->toContain('16.07.2026 12:30 UTC')
        ->and(json_encode($message->toRequestArray()))->not->toContain('каждые 10 минут');
});

test('it places the native chart photo next to player count dynamics', function () {
    $analytics = new LeaderboardAnalyticsData(
        playerCountTrends: [new PlayerCountTrendData('day', 105, 100, 5)],
        mostActivePlayers: [],
        momentumPlayers: [],
    );
    $artifact = new ChartArtifact('/private/chart.png', 'image/png', 1200, 675, 'key');
    $photo = (new AnalyticsChartMediaFactory)->make($artifact);

    $message = (new AnalyticsRichMessageFactory)->make($analytics, $photo);
    $photoIndex = collect($message->blocks)->search(fn ($block): bool => $block instanceof InputRichBlockPhoto);
    $firstTableIndex = collect($message->blocks)->search(fn ($block): bool => $block instanceof InputRichBlockTable);

    expect($photoIndex)->toBe($firstTableIndex + 1)
        ->and($message->blocks[$photoIndex]->photo->media->pathOrResource)->toBe('/private/chart.png')
        ->and($message->blocks[$photoIndex]->caption->text)->toContain('частичные');
});

test('it builds an empty state when analytics are unavailable', function () {
    $message = (new AnalyticsRichMessageFactory)->make(new LeaderboardAnalyticsData([], [], []));

    expect($message->toRequestArray()['blocks'])->toHaveCount(2);
});
