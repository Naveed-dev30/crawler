<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Proposal extends Model
{
    use HasFactory;

    protected $casts = [
        'skills' => 'array',
        'qualified' => 'boolean',
    ];

    /**
     * Get the Bid associated with the Proposal
     *
     * @return HasOne
     */
    public function bid()
    {
        return $this->hasOne(Bid::class);
    }

    public function scopeNeedsReview($query)
    {
        return $query->whereNull('review_label');
    }

    public function scopeNotQualified($query)
    {
        return $query->where('qualified', false);
    }
}
