<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PixelWorldLeaderboardPeriodEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'period_id', 'player_uuid', 'place', 'points', 'nickname', 'level',
        'has_premium', 'image_url', 'mask_image_url',
    ];

    protected function casts(): array
    {
        return ['has_premium' => 'boolean'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PixelWorldLeaderboardPeriod::class, 'period_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(PixelWorldPlayer::class, 'player_uuid');
    }
}
