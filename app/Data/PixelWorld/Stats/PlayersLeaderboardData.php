<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Stats;

use Spatie\LaravelData\Data;

class PlayersLeaderboardData extends Data
{
    public function __construct(
        public string $range,
        public LeaderboardPlayerData $user,
        public PlayersLeaderboardListData $list,
    ) {}
}
