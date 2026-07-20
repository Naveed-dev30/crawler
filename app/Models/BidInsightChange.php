<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidInsightChange extends Model
{
    protected $fillable = [
        'bid_insight_id',
        'field',
        'old_value',
        'new_value',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function bidInsight(): BelongsTo
    {
        return $this->belongsTo(BidInsight::class);
    }
}
