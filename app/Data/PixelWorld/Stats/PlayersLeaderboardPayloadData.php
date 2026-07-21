<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Stats;

use Spatie\LaravelData\Data;

class PlayersLeaderboardPayloadData extends Data
{
    public function __construct(public PlayersLeaderboardData $leaderboard) {}
}
