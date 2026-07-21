<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class PlayerCountTrendData
{
    public function __construct(
        public string $range,
        public int $current,
        public ?int $previous,
        public ?int $delta,
    ) {}
}
