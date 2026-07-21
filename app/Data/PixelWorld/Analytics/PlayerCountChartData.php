<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class PlayerCountChartData
{
    /**
     * @param  array<PlayerCountChartSeries>  $series
     * @param  array<string, int>  $periodLimits
     */
    public function __construct(
        public array $series,
        public array $periodLimits,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->series as $series) {
            if ($series->points !== []) {
                return false;
            }
        }

        return true;
    }
}
