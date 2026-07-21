<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PixelWorldPlayerTotalHourly extends Model
{
    protected $fillable = [
        'range',
        'hour_at',
        'sample_count',
        'minimum_total',
        'maximum_total',
        'average_total',
        'first_total',
        'last_total',
    ];

    protected function casts(): array
    {
        return [
            'hour_at' => 'immutable_datetime',
            'sample_count' => 'integer',
            'minimum_total' => 'integer',
            'maximum_total' => 'integer',
            'average_total' => 'decimal:2',
            'first_total' => 'integer',
            'last_total' => 'integer',
        ];
    }
}
