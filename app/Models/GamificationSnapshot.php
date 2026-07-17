<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GamificationSnapshot extends Model
{
    protected $fillable = [
        'scraped_at',
        'self_rank',
        'self_score',
        'self_level',
        'self_username',
        'self_public_name',
        'top5',
        'raw',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
        'top5' => 'array',
    ];
}
