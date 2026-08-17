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
        // Identity. The projects API redacts these (verified Aug 2026: owner_id
        // is null and owner_info carries no id/name), so for a project we have
        // no conversation on, the browser extension reading the public project
        // page is the only source. One-time semantics mean an extension value
        // never overwrites one the users endpoint already resolved.
        'client_name',
        'client_username',
        'client_avatar',
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
        'client_country_flag',
        'client_name',
        'client_username',
        'client_avatar',
        'client_member_since',
        'client_verification',
        'client_rating',
        'client_reviews',
        'bid_rank',
        'total_bids',
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
        'client_verification' => 'array',
        'upgrades' => 'array',
        'raw' => 'array',
        'time_submitted' => 'datetime',
        'client_member_since' => 'datetime',
        'last_scraped_at' => 'datetime',
    ];

    public function changes(): HasMany
    {
        return $this->hasMany(BidInsightChange::class);
    }
}
