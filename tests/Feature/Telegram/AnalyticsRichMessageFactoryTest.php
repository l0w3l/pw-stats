<?php

use App\Data\PixelWorld\Analytics\ChartArtifact;
use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerActivityData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PlayerMomentumData;
use App\Models\TelegramNotification;
use App\Telegram\Messages\AnalyticsChartMediaFactory;
use App\Telegram\Messages\AnalyticsRichMessageFactory;
use App\Telegram\Messages\SettingsRichMessageFactory;
use Carbon\CarbonImmutable;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\InputRichBlockSectionHeading;
use Phptg\BotApi\Type\InputRichBlockTable;

function localizedAnalytics(): LeaderboardAnalyticsData
{
    return new LeaderboardAnalyticsData(
        playerCountTrends: [
            new PlayerCountTrendData('day', 105, 100, 5),
            new PlayerCountTrendData('week', 205, null, null),
            new PlayerCountTrendData('month', 305, 310, -5),
        ],
        mostActivePlayers: [new PlayerActivityData('week', 'uuid', 'Slayer', 70, 10.0)],
        momentumPlayers: [new PlayerMomentumData('uuid', 'Slayer', 5, 3)],
        latestCollectedAt: CarbonImmutable::parse('2026-07-16 12:30:00', 'UTC'),
    );
}

test('digest contains only localized player totals and deltas', function (string $locale, string $heading, string $period) {
    app()->setLocale($locale === 'ru' ? 'en' : 'ru');

    $message = (new AnalyticsRichMessageFactory)->make(localizedAnalytics(), locale: $locale);
    $payload = json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $tables = collect($message->blocks)->filter(fn ($block): bool => $block instanceof InputRichBlockTable);

    expect($message->blocks)->toHaveCount(2)
        ->and(array_map(fn (object $block): string => $block::class, $message->blocks))->toBe([
            InputRichBlockSectionHeading::class,
            InputRichBlockTable::class,
        ])
        ->and($tables)->toHaveCount(1)
        ->and($tables->first()->cells[1][0]->text)->toBe($period)
        ->and($tables->first()->cells[1][1]->text)->toBe('105')
        ->and($tables->first()->cells[1][2]->text)->toBe('+5')
        ->and($payload)->toContain($heading)
        ->and($payload)->not->toContain('Slayer')
        ->and($payload)->not->toContain('16.07.2026')
        ->and($payload)->not->toContain('momentum')
        ->and(app()->getLocale())->toBe($locale === 'ru' ? 'en' : 'ru');
})->with([
    'Russian' => ['ru', 'Pixel World · Статистика', 'День'],
    'English' => ['en', 'Pixel World · Statistics', 'Day'],
]);

test('unsupported and missing locales fall back to Russian', function (?string $locale) {
    $message = (new AnalyticsRichMessageFactory)->make(localizedAnalytics(), locale: $locale);
    $payload = json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($payload)->toContain('Pixel World · Статистика')
        ->and($payload)->toContain('Период');
})->with(['unsupported' => 'de', 'empty' => '', 'missing' => null]);

test('chart remains adjacent to the table and has an explicit localized caption', function () {
    $artifact = new ChartArtifact('/private/chart.png', 'image/png', 1200, 675, 'key');
    $photo = (new AnalyticsChartMediaFactory)->make($artifact, 'en');
    $message = (new AnalyticsRichMessageFactory)->make(localizedAnalytics(), $photo, 'en');
    $photoIndex = collect($message->blocks)->search(fn ($block): bool => $block instanceof InputRichBlockPhoto);
    $tableIndex = collect($message->blocks)->search(fn ($block): bool => $block instanceof InputRichBlockTable);

    expect($photoIndex)->toBe($tableIndex + 1)
        ->and($message->blocks[$photoIndex]->photo->media->pathOrResource)->toBe('/private/chart.png')
        ->and($message->blocks[$photoIndex]->caption->text)->toBe('Player count history.');
});

test('empty digest is compact and localized', function () {
    $message = (new AnalyticsRichMessageFactory)->make(new LeaderboardAnalyticsData([], [], []), locale: 'en');

    expect($message->toRequestArray()['blocks'])->toHaveCount(2)
        ->and(json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR))->toContain('No data.');
});

test('settings contains only localized statistics and exact RU and EN controls', function (
    string $locale,
    string $heading,
    string $period,
) {
    // Arrange: include every legacy analytics field so omissions are behaviorally observable.
    $subscription = new TelegramNotification([
        'id' => 17,
        'send_time' => '14:30:00',
        'enabled' => true,
    ]);
    $subscription->id = 17;

    // Act: render the requested subscription locale.
    $view = (new SettingsRichMessageFactory)->make(localizedAnalytics(), $subscription, $locale);
    $payload = json_encode($view->message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $keyboard = $view->keyboard->toRequestArray()['inline_keyboard'];

    // Assert: only heading/table statistics and necessary controls remain.
    expect($view->message->blocks)->toHaveCount(2)
        ->and(array_map(fn (object $block): string => $block::class, $view->message->blocks))->toBe([
            InputRichBlockSectionHeading::class,
            InputRichBlockTable::class,
        ])
        ->and($payload)->toContain($heading, $period)
        ->and($payload)->not->toContain('Slayer')
        ->and($payload)->not->toContain('2026', '12:30', '14:30', 'momentum')
        ->and(array_column($keyboard[1], 'text'))->toBe(['RU', 'EN'])
        ->and($keyboard[1][0]['callback_data'])->toBe('notifications:locale:ru:17')
        ->and($keyboard[1][1]['callback_data'])->toBe('notifications:locale:en:17')
        ->and(json_encode($keyboard, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->not->toContain('🇷🇺')
        ->not->toContain('🇬🇧');
})->with([
    'Russian' => ['ru', 'Pixel World · Настройки', 'День'],
    'English' => ['en', 'Pixel World · Settings', 'Day'],
]);
