<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightSnapshot extends Model
{
    protected $fillable = [
        'scraped_at',
        'earnings_total',
        'earnings_30d',
        'bids_remaining',
        'unearned_bids',
        'overall_ranking',
        'job_proficiency',
        'rating_per_skill',
        'earnings_per_skill',
        'ranking_per_skill',
        'high_demand_skills',
        'trending_skills',
        'bids_per_milestone',
        'profile_views_week',
        'profile_views_year',
        'earnings_over_time',
        'bid_conversion',
        'raw',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
        'earnings_total' => 'decimal:2',
        'earnings_30d' => 'decimal:2',
        'job_proficiency' => 'array',
        'rating_per_skill' => 'array',
        'earnings_per_skill' => 'array',
        'ranking_per_skill' => 'array',
        'high_demand_skills' => 'array',
        'trending_skills' => 'array',
        'bids_per_milestone' => 'array',
        'profile_views_week' => 'array',
        'profile_views_year' => 'array',
        'earnings_over_time' => 'array',
        'bid_conversion' => 'array',
    ];
}
