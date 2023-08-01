<?php

namespace App\Models;

use App\Models\Proposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Bid extends Model
{
    use HasFactory, Notifiable;


    /**
     * Get the proposal that owns the Bid
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
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

    public function routeNotificationForSlack()
    {
        return env('LOG_SLACK_WEBHOOK_URL');
    }
}
