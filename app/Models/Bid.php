<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class Bid extends Model
{
    use HasFactory, Notifiable;

    protected $casts = [
        'awarded' => 'boolean',
        'awarded_price' => 'float',
        'posted_at' => 'datetime',
        'last_action_at' => 'datetime',
    ];

    /**
     * error_message fragments that mean the Freelancer account has run out of
     * bids for the period ("max bid reached" / bid limit exceeded). SQL LIKE
     * patterns — % is a wildcard.
     */
    public const OUT_OF_BID_LIKE = [
        '%used all of your bids%',   // Freelancer's actual message
        '%all of your bids%',
        '%out of bids%',
        '%no bids left%',
        '%bids remaining%',
        '%bid limit%',
        '%bidlimit%',
        '%maximum number of bids%',
        '%reached your bid%',
    ];

    /**
     * True when the given error message signals the bid limit was hit.
     */
    public static function messageIsOutOfBid(?string $message): bool
    {
        $msg = strtolower((string) $message);
        foreach (self::OUT_OF_BID_LIKE as $like) {
            if (fnmatch(str_replace('%', '*', strtolower($like)), $msg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Failures caused by the Freelancer bid limit being reached.
     */
    public function scopeOutOfBid($query)
    {
        return $query->where(function ($q) {
            foreach (self::OUT_OF_BID_LIKE as $like) {
                $q->orWhere('bids.error_message', 'like', $like);
            }
        });
    }

    /**
     * Failures that are NOT the bid limit (null/empty error_message included).
     */
    public function scopeNotOutOfBid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('bids.error_message')
                ->orWhere(function ($qq) {
                    foreach (self::OUT_OF_BID_LIKE as $like) {
                        $qq->where('bids.error_message', 'not like', $like);
                    }
                });
        });
    }

    public function getIsOutOfBidAttribute(): bool
    {
        return self::messageIsOutOfBid($this->error_message);
    }

    /**
     * Get the proposal that owns the Bid
     *
     * @return BelongsTo
     */
    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function scopeLatestThirtyDays($query)
    {
        return $query->where('created_at', '>=', now()->subDays(30));
    }

    public function scopeLatestYear($query)
    {
        return $query->where('created_at', '>=', now()->subYear());
    }

    public function scopeWhereSeen($query)
    {
        return $query->where('is_seen', '=', 1);
    }

    public function scopeGroupByDate($query)
    {
        return $query->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date');
    }
}
