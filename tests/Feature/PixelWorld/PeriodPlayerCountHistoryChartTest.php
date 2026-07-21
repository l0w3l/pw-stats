<?php

use App\Data\PixelWorld\Analytics\PlayerCountChartData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Queries\PeriodPlayerCountHistory;
use App\Services\PixelWorld\Charts\ChartRenderer;
use App\Services\PixelWorld\Charts\PlayerCountChartService;
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

        public function render(PlayerCountChartData $data, int $width, int $height): string
        {
            $this->renders++;

            return 'fake-png';
        }
    };

    try {
        $service = new PlayerCountChartService(new PeriodPlayerCountHistory, $renderer);
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
