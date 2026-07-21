<?php

declare(strict_types=1);

namespace App\Services\PixelWorld\Leaderboard;

use App\Data\PixelWorld\Stats\LeaderboardPlayerData;

class LeaderboardCollectionBuffer
{
    /** @var array<string, LeaderboardPlayerData> */
    private array $playersByUuid = [];

    /** @var array<int, string> */
    private array $uuidByPlace = [];

    public function __construct(private int $total) {}

    /** @param array<LeaderboardPlayerData> $players */
    public function append(array $players): void
    {
        foreach ($players as $player) {
            $previousAtPlace = $this->uuidByPlace[$player->place] ?? null;

            if ($previousAtPlace !== null) {
                unset($this->playersByUuid[$previousAtPlace]);
            }

            if (isset($this->playersByUuid[$player->uuid])) {
                unset($this->uuidByPlace[$this->playersByUuid[$player->uuid]->place]);
            }

            $this->playersByUuid[$player->uuid] = $player;
            $this->uuidByPlace[$player->place] = $player->uuid;
        }
    }

    public function synchronizeTotal(int $total): void
    {
        $this->total = $total;

        foreach ($this->playersByUuid as $uuid => $player) {
            if ($player->place < 1 || $player->place > $total) {
                unset($this->playersByUuid[$uuid], $this->uuidByPlace[$player->place]);
            }
        }
    }

    /** @return array<int> */
    public function missingPlaces(): array
    {
        $missing = [];

        for ($place = 1; $place <= $this->total; $place++) {
            if (! isset($this->uuidByPlace[$place])) {
                $missing[] = $place;
            }
        }

        return $missing;
    }

    /** @return array<LeaderboardPlayerData> */
    public function players(): array
    {
        return array_values($this->playersByUuid);
    }

    public function total(): int
    {
        return $this->total;
    }
}
