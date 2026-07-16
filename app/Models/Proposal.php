<?php

namespace App\Models;

use App\Models\Bid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Proposal extends Model
{
    use HasFactory;

    protected $casts = [
        'skills' => 'array',
    ];

    /**
     * Get the Bid associated with the Proposal
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function bid()
    {
        return $this->hasOne(Bid::class);
    }

    public function scopeNeedsReview($query)
    {
        return $query->whereNull('review_label');
    }
}
