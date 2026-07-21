<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class PlayerMomentumData
{
    public function __construct(
        public string $playerUuid,
        public string $nickname,
        public int $killsDelta,
        public int $rankDelta,
    ) {}
}
