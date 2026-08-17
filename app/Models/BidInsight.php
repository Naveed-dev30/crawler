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
        'bid_rating',
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
        'bid_rating',
        'client_engagement',
        'last_scraped_at',
        'raw',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'winning_bid_amount' => 'decimal:2',
        'client_rating' => 'decimal:2',
        'bid_rating' => 'decimal:1',
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

    /** Did the client open our bid? First of the three "Actions Taken" icons. */
    public function clientSawBid(): bool
    {
        return $this->actionFlag(['client_saw_your_bid', 'viewed_by_client']);
    }

    /** Did the client click through to our profile? Second icon. */
    public function clientSawProfile(): bool
    {
        return $this->actionFlag(['client_saw_your_profile', 'viewed_your_profile']);
    }

    /**
     * Third icon. Freelancer reports an unrated bid as 0, never as null, so a
     * row that was captured but not yet rated is false rather than unknown.
     */
    public function clientRatedBid(): bool
    {
        return (float) ($this->bid_rating ?? 0) > 0;
    }

    /**
     * actions_taken arrives from the extension as a boolean map keyed by action
     * name. The original ingest contract assumed a flat list of the actions that
     * had been taken, so rows captured back then still hold that shape — read
     * both rather than rewriting historical JSON.
     */
    private function actionFlag(array $aliases): bool
    {
        $actions = $this->actions_taken;

        if (! is_array($actions)) {
            return false;
        }

        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $actions)) {
                return (bool) $actions[$alias];
            }

            if (in_array($alias, $actions, true)) {
                return true;
            }
        }

        return false;
    }
}
