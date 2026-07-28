<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class PointsThresholdData
{
    public function __construct(
        public string $range,
        public ?int $playersAtLeast50,
        public ?int $playersAtLeast100,
        public ?int $playersAtLeast250,
    ) {}
}
