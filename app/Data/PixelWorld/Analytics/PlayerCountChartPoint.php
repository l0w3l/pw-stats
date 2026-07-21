<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

use Carbon\CarbonImmutable;

final readonly class PlayerCountChartPoint
{
    public function __construct(
        public int $periodId,
        public int $total,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $collectedAt,
        public bool $isPartial,
    ) {}
}
