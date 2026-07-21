<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PixelWorldLeaderboardPeriod extends Model
{
    protected $fillable = [
        'range',
        'period_start',
        'period_end',
        'total',
        'entries_count',
        'missing_places',
        'is_partial',
        'last_collected_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'last_collected_at' => 'immutable_datetime',
            'is_partial' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PixelWorldLeaderboardPeriodEntry::class, 'period_id');
    }
}
