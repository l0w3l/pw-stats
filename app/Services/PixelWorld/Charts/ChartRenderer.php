<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Charts;

use App\Data\PixelWorld\Analytics\PlayerCountChartData;

interface ChartRenderer
{
    public function version(): string;

    /** Returns PNG bytes. */
    public function render(PlayerCountChartData $data, int $width, int $height, string $locale): string;
}
