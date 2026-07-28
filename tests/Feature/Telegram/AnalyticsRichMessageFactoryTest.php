<?php

use App\Data\PixelWorld\Analytics\LeaderboardAnalyticsData;
use App\Data\PixelWorld\Analytics\PlayerActivityData;
use App\Data\PixelWorld\Analytics\PlayerCountTrendData;
use App\Data\PixelWorld\Analytics\PlayerMomentumData;
use App\Data\PixelWorld\Analytics\PointsThresholdData;
use App\Models\TelegramNotification;
use App\Telegram\Messages\AnalyticsRichMessageFactory;
use App\Telegram\Messages\SettingsRichMessageFactory;
use Carbon\CarbonImmutable;
use Phptg\BotApi\Type\InputRichBlockParagraph;
use Phptg\BotApi\Type\InputRichBlockPhoto;
use Phptg\BotApi\Type\InputRichBlockSectionHeading;
use Phptg\BotApi\Type\InputRichBlockTable;
use Phptg\BotApi\Type\RichTextBold;

function localizedAnalytics(): LeaderboardAnalyticsData
{
    return new LeaderboardAnalyticsData(
        playerCountTrends: [
            new PlayerCountTrendData('day', 105, 100, 5),
            new PlayerCountTrendData('week', 205, null, null),
            new PlayerCountTrendData('month', 305, 310, -5),
        ],
        pointsThresholds: [
            new PointsThresholdData('day', 9, 4, 0),
            new PointsThresholdData('month', 30, 20, 10),
        ],
        mostActivePlayers: [new PlayerActivityData('week', 'uuid', 'Slayer', 70, 10.0)],
        momentumPlayers: [new PlayerMomentumData('uuid', 'Slayer', 5, 3)],
        latestCollectedAt: CarbonImmutable::parse('2026-07-16 12:30:00', 'UTC'),
    );
}

/** @return array<int, array<int, string>> */
function richTableText(InputRichBlockTable $table): array
{
    return array_map(
        fn (array $row): array => array_map(
            fn (object $cell): string => $cell->text instanceof RichTextBold
                ? $cell->text->text
                : $cell->text,
            $row,
        ),
        $table->cells,
    );
}

/** @return array<int, array<int, string>> */
function expectedPlayerTable(string $locale): array
{
    return $locale === 'en'
        ? [
            ['Period', 'Players', 'Δ'],
            ['Day', '105', '+5'],
            ['Week', '205', '—'],
            ['Month', '305', '-5'],
        ]
        : [
            ['Период', 'Игроки', 'Δ'],
            ['День', '105', '+5'],
            ['Неделя', '205', '—'],
            ['Месяц', '305', '-5'],
        ];
}

/** @return array<int, array<int, string>> */
function expectedPointsTable(string $locale): array
{
    return [
        [$locale === 'en' ? 'Kills' : 'Убийства', 'DAY', 'WEEK', 'MONTH'],
        ['250+', '0', '—', '10'],
        ['100+', '4', '—', '20'],
        ['50+', '9', '—', '30'],
    ];
}

/** @return list<string> */
function expectedTableTitles(string $locale): array
{
    return $locale === 'en'
        ? ['Player counts', 'Players by kills']
        : ['Количество игроков', 'Игроки по убийствам'];
}

test('digest renders exact localized player and points tables without a photo', function (string $locale, string $heading) {
    // Arrange: preserve the application locale and include present, missing, and zero thresholds.
    app()->setLocale($locale === 'ru' ? 'en' : 'ru');

    // Act: render the digest in the explicitly requested locale.
    $message = (new AnalyticsRichMessageFactory)->make(localizedAnalytics(), monthlyKills: 9010, locale: $locale);
    $payload = json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $tables = collect($message->blocks)
        ->filter(fn ($block): bool => $block instanceof InputRichBlockTable)
        ->values();

    // Assert: totals stay unchanged, threshold orientation is exact, and no graph is emitted.
    expect($message->blocks)->toHaveCount(4)
        ->and(array_map(fn (object $block): string => $block::class, $message->blocks))->toBe([
            InputRichBlockSectionHeading::class,
            InputRichBlockTable::class,
            InputRichBlockTable::class,
            InputRichBlockParagraph::class,
        ])
        ->and($tables->pluck('caption')->all())->toBe(expectedTableTitles($locale))
        ->and(richTableText($tables[0]))->toBe(expectedPlayerTable($locale))
        ->and(richTableText($tables[1]))->toBe(expectedPointsTable($locale))
        ->and(collect($message->blocks)->contains(fn ($block): bool => $block instanceof InputRichBlockPhoto))->toBeFalse()
        ->and($payload)->toContain($heading)
        ->and($payload)->toContain($locale === 'en'
            ? 'Kills this month: 9 010 (≈30 kills per player or 2 min in game)'
            : 'Убийств за месяц: 9 010 (≈30 на игрока или 2 мин в игре)')
        ->and($payload)->not->toContain('Slayer')
        ->and($payload)->not->toContain('16.07.2026')
        ->and($payload)->not->toContain('momentum')
        ->and(app()->getLocale())->toBe($locale === 'ru' ? 'en' : 'ru');
})->with([
    'Russian' => ['ru', 'Pixel World · Статистика'],
    'English' => ['en', 'Pixel World · Statistics'],
]);

test('unsupported and missing locales fall back to Russian without mutating the application locale', function (
    string $factory,
    ?string $locale,
) {
    // Arrange: use English globally so an accidental locale mutation is observable.
    app()->setLocale('en');
    $subscription = new TelegramNotification(['id' => 17, 'enabled' => true]);
    $subscription->id = 17;

    // Act: render either report with an unsupported or absent locale.
    $message = $factory === 'digest'
        ? (new AnalyticsRichMessageFactory)->make(localizedAnalytics(), locale: $locale)
        : (new SettingsRichMessageFactory)->make(localizedAnalytics(), $subscription, $locale)->message;
    $tables = collect($message->blocks)
        ->filter(fn ($block): bool => $block instanceof InputRichBlockTable)
        ->values();
    $payload = json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    // Assert: both factories use the complete Russian fallback without changing global state.
    expect($payload)->toContain($factory === 'digest' ? 'Pixel World · Статистика' : 'Pixel World · Настройки')
        ->and(richTableText($tables[0]))->toBe(expectedPlayerTable('ru'))
        ->and(richTableText($tables[1]))->toBe(expectedPointsTable('ru'))
        ->and($tables->pluck('caption')->all())->toBe(expectedTableTitles('ru'))
        ->and(collect($message->blocks)->contains(fn ($block): bool => $block instanceof InputRichBlockPhoto))->toBeFalse()
        ->and(app()->getLocale())->toBe('en');
})->with([
    'digest unsupported' => ['digest', 'de'],
    'digest empty' => ['digest', ''],
    'digest missing' => ['digest', null],
    'settings unsupported' => ['settings', 'de'],
    'settings empty' => ['settings', ''],
    'settings missing' => ['settings', null],
]);

test('empty digest is compact and localized', function () {
    // Arrange: provide no player totals or points thresholds.
    $analytics = new LeaderboardAnalyticsData(
        playerCountTrends: [],
        pointsThresholds: [],
        mostActivePlayers: [],
        momentumPlayers: [],
    );

    // Act: render an empty English digest.
    $message = (new AnalyticsRichMessageFactory)->make($analytics, locale: 'en');

    // Assert: the empty state remains compact and localized.
    expect($message->toRequestArray()['blocks'])->toHaveCount(2)
        ->and(json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR))->toContain('No data.');
});

test('monthly kills omit the average when the monthly player count is unavailable', function () {
    $analytics = new LeaderboardAnalyticsData(
        playerCountTrends: [new PlayerCountTrendData('day', 10, null, null)],
        pointsThresholds: [],
        mostActivePlayers: [],
        momentumPlayers: [],
    );

    $message = (new AnalyticsRichMessageFactory)->make($analytics, monthlyKills: 100, locale: 'en');
    $payload = json_encode($message->toRequestArray(), JSON_THROW_ON_ERROR);

    expect($payload)->toContain('Kills this month: 100')
        ->and($payload)->not->toContain('kills per player');
});

test('settings contains only localized statistics and exact RU and EN controls', function (
    string $locale,
    string $heading,
) {
    // Arrange: include every legacy analytics field so omissions are behaviorally observable.
    $subscription = new TelegramNotification([
        'id' => 17,
        'send_time' => '14:30:00',
        'enabled' => true,
    ]);
    $subscription->id = 17;

    // Act: render the requested subscription locale.
    $view = (new SettingsRichMessageFactory)->make(
        localizedAnalytics(),
        $subscription,
        $locale,
        monthlyKills: 9010,
    );
    $payload = json_encode($view->message->toRequestArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $keyboard = $view->keyboard->toRequestArray()['inline_keyboard'];

    $tables = collect($view->message->blocks)
        ->filter(fn ($block): bool => $block instanceof InputRichBlockTable)
        ->values();

    // Assert: exact totals and thresholds precede the unchanged necessary controls, without a graph.
    expect($view->message->blocks)->toHaveCount(4)
        ->and(array_map(fn (object $block): string => $block::class, $view->message->blocks))->toBe([
            InputRichBlockSectionHeading::class,
            InputRichBlockTable::class,
            InputRichBlockTable::class,
            InputRichBlockParagraph::class,
        ])
        ->and($tables->pluck('caption')->all())->toBe(expectedTableTitles($locale))
        ->and(richTableText($tables[0]))->toBe(expectedPlayerTable($locale))
        ->and(richTableText($tables[1]))->toBe(expectedPointsTable($locale))
        ->and(collect($view->message->blocks)->contains(fn ($block): bool => $block instanceof InputRichBlockPhoto))->toBeFalse()
        ->and($payload)->toContain($heading)
        ->and($payload)->toContain($locale === 'en'
            ? 'Kills this month: 9 010 (≈30 kills per player or 2 min in game)'
            : 'Убийств за месяц: 9 010 (≈30 на игрока или 2 мин в игре)')
        ->and($payload)->not->toContain('Slayer')
        ->and($payload)->not->toContain('2026', '12:30', '14:30', 'momentum')
        ->and(array_column($keyboard[1], 'text'))->toBe(['RU', 'EN'])
        ->and($keyboard[1][0]['callback_data'])->toBe('notifications:locale:ru:17')
        ->and($keyboard[1][1]['callback_data'])->toBe('notifications:locale:en:17')
        ->and(json_encode($keyboard, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->not->toContain('🇷🇺')
        ->not->toContain('🇬🇧');
})->with([
    'Russian' => ['ru', 'Pixel World · Настройки'],
    'English' => ['en', 'Pixel World · Settings'],
]);
