<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Stats;

use Spatie\LaravelData\Data;

class PlayersLeaderboardResponseData extends Data
{
    public function __construct(
        public bool $ok,
        public PlayersLeaderboardPayloadData $data,
    ) {}
}
