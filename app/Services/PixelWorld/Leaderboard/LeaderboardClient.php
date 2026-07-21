<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Data\PixelWorld\Stats\PlayersLeaderboardResponseData;
use App\Services\PixelWorld\Stats\LeaderboardRange;

interface LeaderboardClient
{
    public function page(LeaderboardRange $range, int $page, int $limit): PlayersLeaderboardResponseData;
}
