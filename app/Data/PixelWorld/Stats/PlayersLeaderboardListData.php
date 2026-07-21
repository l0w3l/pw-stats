<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Stats;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class PlayersLeaderboardListData extends Data
{
    /** @param array<LeaderboardPlayerData> $players */
    public function __construct(
        public int $page,
        public int $total,
        #[DataCollectionOf(LeaderboardPlayerData::class)]
        public array $players,
    ) {}
}
