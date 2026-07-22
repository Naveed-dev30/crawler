<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BidInsight extends Model
{
    public const ONE_TIME_FIELDS = [
        'bid_id',
        'project_url',
        'time_to_bid_seconds',
        'time_submitted',
        'bid_amount',
        'bid_currency',
        'description',
        'upgrades',
        'client_country',
        'client_rating',
        'client_reviews',
    ];

    public const RECURRING_FIELDS = [
        'bid_rank',
        'winning_bid_amount',
        'winning_bid_sealed',
        'winning_bid_text',
        'actions_taken',
        'client_engagement',
    ];

    protected $fillable = [
        'project_id',
        'bid_id',
        'project_url',
        'time_to_bid_seconds',
        'time_submitted',
        'bid_amount',
        'bid_currency',
        'description',
        'upgrades',
        'client_country',
        'client_rating',
        'client_reviews',
        'bid_rank',
        'winning_bid_amount',
        'winning_bid_sealed',
        'winning_bid_text',
        'actions_taken',
        'client_engagement',
        'last_scraped_at',
        'raw',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'winning_bid_amount' => 'decimal:2',
        'client_rating' => 'decimal:2',
        'winning_bid_sealed' => 'boolean',
        'actions_taken' => 'array',
        'client_engagement' => 'array',
        'upgrades' => 'array',
        'raw' => 'array',
        'time_submitted' => 'datetime',
        'last_scraped_at' => 'datetime',
    ];

    public function changes(): HasMany
    {
        return $this->hasMany(BidInsightChange::class);
    }
}
