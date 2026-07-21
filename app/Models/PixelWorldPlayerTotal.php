<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PixelWorldPlayerTotal extends Model
{
    protected $fillable = [
        'range',
        'total',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'collected_at' => 'immutable_datetime',
        ];
    }
}
