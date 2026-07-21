<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class PlayerActivityData
{
    public function __construct(
        public string $range,
        public string $playerUuid,
        public string $nickname,
        public int $kills,
        public float $killsPerDay,
    ) {}
}
