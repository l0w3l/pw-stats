<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PixelWorldPlayer extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'uuid',
        'image_url',
        'mask_image_url',
        'level',
        'nickname',
        'has_premium',
    ];

    protected function casts(): array
    {
        return [
            'has_premium' => 'boolean',
        ];
    }

    public function leaderboardEntries(): HasMany
    {
        return $this->hasMany(PixelWorldLeaderboardPeriodEntry::class, 'player_uuid');
    }
}
