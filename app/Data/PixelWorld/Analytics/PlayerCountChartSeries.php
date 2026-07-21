<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class PlayerCountChartSeries
{
    /** @param array<PlayerCountChartPoint> $points */
    public function __construct(
        public string $range,
        public array $points,
    ) {}
}
