<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Charts;

use App\Data\PixelWorld\Analytics\ChartArtifact;
use App\Data\PixelWorld\Analytics\PlayerCountChartData;
use App\Queries\PeriodPlayerCountHistory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PlayerCountChartService
{
    public function __construct(
        private readonly PeriodPlayerCountHistory $history,
        private readonly ChartRenderer $renderer,
    ) {}

    public function generate(): ?ChartArtifact
    {
        $periodLimits = [
            'day' => max(2, (int) config('analytics_chart.period_limits.day', 30)),
            'week' => max(2, (int) config('analytics_chart.period_limits.week', 12)),
            'month' => max(2, (int) config('analytics_chart.period_limits.month', 12)),
        ];
        $width = max(320, (int) config('analytics_chart.width', 1200));
        $height = max(240, (int) config('analytics_chart.height', 675));
        $data = $this->history->get($periodLimits);

        if ($data->isEmpty()) {
            return null;
        }

        $key = $this->cacheKey($data, $width, $height);
        $directory = (string) config('analytics_chart.cache_path', storage_path('app/private/analytics-charts'));
        $path = $directory.'/'.$key.'.png';

        if (is_file($path)) {
            return new ChartArtifact($path, 'image/png', $width, $height, $key);
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create chart cache directory: {$directory}");
        }

        try {
            Cache::lock('analytics-chart:'.$key, (int) config('analytics_chart.lock_seconds', 30))
                ->block((int) config('analytics_chart.lock_wait_seconds', 5), function () use ($data, $width, $height, $path, $directory): void {
                    if (is_file($path)) {
                        return;
                    }

                    $temporary = tempnam($directory, '.chart-');

                    if ($temporary === false) {
                        throw new RuntimeException('Unable to create a temporary chart file.');
                    }

                    try {
                        $png = $this->renderer->render($data, $width, $height);

                        if (file_put_contents($temporary, $png, LOCK_EX) === false) {
                            throw new RuntimeException('Unable to write the chart file.');
                        }

                        chmod($temporary, 0600);

                        if (! rename($temporary, $path)) {
                            throw new RuntimeException('Unable to publish the chart file atomically.');
                        }
                    } finally {
                        if (is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Timed out waiting for chart rendering.', previous: $exception);
        }

        $this->removeExpiredFiles($directory, $path);

        return new ChartArtifact($path, 'image/png', $width, $height, $key);
    }

    private function cacheKey(PlayerCountChartData $data, int $width, int $height): string
    {
        $series = [];

        foreach ($data->series as $item) {
            $series[$item->range] = array_map(static fn ($point): array => [
                $point->periodId,
                $point->total,
                $point->isPartial,
                $point->collectedAt->timestamp,
            ], $item->points);
        }

        $payload = json_encode([
            'type' => 'player-count-history',
            'series' => $series,
            'width' => $width,
            'height' => $height,
            'period_limits' => $data->periodLimits,
            'cache_version' => (string) config('analytics_chart.cache_version', '1'),
            'renderer_version' => $this->renderer->version(),
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    private function removeExpiredFiles(string $directory, string $currentPath): void
    {
        $retentionHours = max(1, (int) config('analytics_chart.cache_retention_hours', 168));
        $paths = glob($directory.'/*.png') ?: [];

        foreach ($paths as $path) {
            if ($path !== $currentPath && filemtime($path) < time() - $retentionHours * 3600) {
                unlink($path);
            }
        }
    }
}
