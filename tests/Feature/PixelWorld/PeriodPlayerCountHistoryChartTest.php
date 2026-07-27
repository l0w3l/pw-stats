<?php

use App\Data\PixelWorld\Analytics\PlayerCountChartData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Queries\PeriodPlayerCountHistory;
use App\Services\PixelWorld\Charts\ChartRenderer;
use App\Services\PixelWorld\Charts\ImagickSvgChartRenderer;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
use App\Telegram\Messages\TelegramTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('period history uses bounded counts and downsamples while preserving endpoints', function () {
    $periods = [];

    foreach (range(1, 7) as $day) {
        $periods[] = chartPeriod('day', sprintf('2026-07-%02d', $day), 100 + $day, partial: $day === 5);
    }

    chartPeriod('week', '2026-07-06', 500);
    $data = (new PeriodPlayerCountHistory)->get(['day' => 4, 'week' => 12, 'month' => 12]);
    $day = collect($data->series)->firstWhere('range', 'day');
    $week = collect($data->series)->firstWhere('range', 'week');

    expect($day->points)->toHaveCount(4)
        ->and($day->points[0]->periodId)->toBe($periods[0]->id)
        ->and($day->points[3]->periodId)->toBe($periods[6]->id)
        ->and(collect($day->points)->contains(fn ($point): bool => $point->isPartial))->toBeTrue()
        ->and($week->points)->toHaveCount(1);
});

test('chart service uses a deterministic period cache with a fake renderer', function () {
    chartPeriod('day', '2026-07-19', 100);
    chartPeriod('day', '2026-07-20', 105);
    $directory = storage_path('framework/testing/analytics-chart-'.bin2hex(random_bytes(4)));
    config()->set('analytics_chart.cache_path', $directory);
    config()->set('analytics_chart.period_limits', ['day' => 30, 'week' => 12, 'month' => 12]);

    $renderer = new class implements ChartRenderer
    {
        public int $renders = 0;

        public function version(): string
        {
            return 'fake-v1';
        }

        public function render(PlayerCountChartData $data, int $width, int $height, string $locale): string
        {
            $this->renders++;

            return 'fake-png';
        }
    };

    try {
        $service = new PlayerCountChartService(new PeriodPlayerCountHistory, $renderer, app(TelegramTranslations::class));
        $first = $service->generate();
        $second = $service->generate();

        expect($first)->not->toBeNull()
            ->and($second)->not->toBeNull()
            ->and($first->cacheKey)->toBe($second->cacheKey)
            ->and(file_get_contents($first->path))->toBe('fake-png')
            ->and($renderer->renders)->toBe(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('chart cache is isolated by normalized locale and reused within that locale', function () {
    chartPeriod('day', '2026-07-20', 105);
    $directory = storage_path('framework/testing/analytics-chart-'.bin2hex(random_bytes(4)));
    config()->set('analytics_chart.cache_path', $directory);

    $renderer = new class implements ChartRenderer
    {
        /** @var list<string> */
        public array $locales = [];

        public function version(): string
        {
            return 'fake-v1';
        }

        public function render(PlayerCountChartData $data, int $width, int $height, string $locale): string
        {
            $this->locales[] = $locale;

            return 'render-'.$locale;
        }
    };

    try {
        $service = new PlayerCountChartService(new PeriodPlayerCountHistory, $renderer, app(TelegramTranslations::class));
        $english = $service->generate('en');
        $englishAgain = $service->generate('en');
        $russian = $service->generate('ru');
        $unsupported = $service->generate('de');

        expect($english)->not->toBeNull()
            ->and($englishAgain?->cacheKey)->toBe($english->cacheKey)
            ->and($russian)->not->toBeNull()
            ->and($russian->cacheKey)->not->toBe($english->cacheKey)
            ->and($unsupported?->cacheKey)->toBe($russian->cacheKey)
            ->and($renderer->locales)->toBe(['en', 'ru']);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('svg renderer localizes visible chart text without changing partial markers', function () {
    chartPeriod('day', '2026-07-20', 105, partial: true);
    $data = (new PeriodPlayerCountHistory)->get(['day' => 30, 'week' => 12, 'month' => 12]);
    $renderer = new ImagickSvgChartRenderer(app(TelegramTranslations::class));
    $svg = new ReflectionMethod($renderer, 'svg');

    $english = $svg->invoke($renderer, $data, 1200, 675, 'en');
    $russian = $svg->invoke($renderer, $data, 1200, 675, 'ru');
    $unsupported = $svg->invoke($renderer, $data, 1200, 675, 'unsupported');

    expect($english)->toContain('Player count history by calendar period', '>Day<', '>No data<')
        ->and($english)->not->toContain('История числа игроков')
        ->and($russian)->toContain('История числа игроков по календарным периодам', '>День<', '>Нет данных<')
        ->and($unsupported)->toBe($russian)
        ->and(substr_count($english, 'stroke="#f59e0b"'))->toBe(1);
});

test('chart cache ignores collection freshness but invalidates rendered point changes', function () {
    $firstPeriod = chartPeriod('day', '2026-07-19', 100);
    $secondPeriod = chartPeriod('day', '2026-07-20', 105);
    $directory = storage_path('framework/testing/analytics-chart-'.bin2hex(random_bytes(4)));
    config()->set('analytics_chart.cache_path', $directory);
    config()->set('analytics_chart.period_limits', ['day' => 30, 'week' => 12, 'month' => 12]);

    $renderer = new class implements ChartRenderer
    {
        public int $renders = 0;

        public function version(): string
        {
            return 'fake-v1';
        }

        public function render(PlayerCountChartData $data, int $width, int $height, string $locale): string
        {
            return 'render-'.++$this->renders;
        }
    };

    try {
        $service = new PlayerCountChartService(new PeriodPlayerCountHistory, $renderer, app(TelegramTranslations::class));
        $initial = $service->generate();

        PixelWorldLeaderboardPeriod::query()
            ->whereKey([$firstPeriod->id, $secondPeriod->id])
            ->update(['last_collected_at' => now()->addHour()]);

        $collectedLater = $service->generate();

        $secondPeriod->update(['total' => 106]);
        $totalChanged = $service->generate();

        $secondPeriod->update(['is_partial' => true]);
        $partialChanged = $service->generate();

        expect($initial)->not->toBeNull()
            ->and($collectedLater)->not->toBeNull()
            ->and($totalChanged)->not->toBeNull()
            ->and($partialChanged)->not->toBeNull()
            ->and($collectedLater->cacheKey)->toBe($initial->cacheKey)
            ->and($collectedLater->path)->toBe($initial->path)
            ->and($totalChanged->cacheKey)->not->toBe($initial->cacheKey)
            ->and($partialChanged->cacheKey)->not->toBe($totalChanged->cacheKey)
            ->and($renderer->renders)->toBe(3);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('chart cache invalidates rendering configuration changes', function () {
    chartPeriod('day', '2026-07-19', 100);
    chartPeriod('day', '2026-07-20', 105);
    $directory = storage_path('framework/testing/analytics-chart-'.bin2hex(random_bytes(4)));
    config()->set('analytics_chart.cache_path', $directory);
    config()->set('analytics_chart.period_limits', ['day' => 30, 'week' => 12, 'month' => 12]);
    config()->set('analytics_chart.width', 1200);
    config()->set('analytics_chart.height', 675);
    config()->set('analytics_chart.cache_version', 'cache-v1');

    $renderer = new class implements ChartRenderer
    {
        public int $renders = 0;

        public string $rendererVersion = 'renderer-v1';

        public function version(): string
        {
            return $this->rendererVersion;
        }

        public function render(PlayerCountChartData $data, int $width, int $height, string $locale): string
        {
            return 'render-'.++$this->renders;
        }
    };

    try {
        $service = new PlayerCountChartService(new PeriodPlayerCountHistory, $renderer, app(TelegramTranslations::class));
        $keys = [$service->generate()?->cacheKey];

        config()->set('analytics_chart.width', 1300);
        $keys[] = $service->generate()?->cacheKey;

        config()->set('analytics_chart.height', 700);
        $keys[] = $service->generate()?->cacheKey;

        config()->set('analytics_chart.period_limits.day', 29);
        $keys[] = $service->generate()?->cacheKey;

        $renderer->rendererVersion = 'renderer-v2';
        $keys[] = $service->generate()?->cacheKey;

        config()->set('analytics_chart.cache_version', 'cache-v2');
        $keys[] = $service->generate()?->cacheKey;

        expect(array_unique($keys))->toHaveCount(6)
            ->and($renderer->renders)->toBe(6);
    } finally {
        File::deleteDirectory($directory);
    }
});

function chartPeriod(string $range, string $start, int $total, bool $partial = false): PixelWorldLeaderboardPeriod
{
    $end = match ($range) {
        'week' => CarbonImmutable::parse($start)->addDays(6)->toDateString(),
        'month' => CarbonImmutable::parse($start)->endOfMonth()->toDateString(),
        default => $start,
    };

    return PixelWorldLeaderboardPeriod::create([
        'range' => $range, 'period_start' => $start, 'period_end' => $end,
        'total' => $total, 'entries_count' => 0, 'missing_places' => $partial ? 1 : 0,
        'is_partial' => $partial, 'last_collected_at' => now(),
    ]);
}
