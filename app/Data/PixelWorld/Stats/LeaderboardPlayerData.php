<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Stats;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class LeaderboardPlayerData extends Data
{
    public function __construct(
        public string $uuid,
        public string $imageUrl,
        public ?string $maskImageUrl,
        public int $level,
        public string $nickname,
        public bool $hasPremium,
        public int $place,
        public int $points,
    ) {}
}
