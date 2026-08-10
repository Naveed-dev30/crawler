<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Filter extends Model
{
    use HasFactory;

    protected $hidden = [
        'created_at',
        'updated_at',
        'id',
    ];

    protected $casts = [
        'ai_enabled' => 'boolean',
        'ai_disabled_at' => 'datetime',
    ];

    /**
     * The countries that belong to the Filter
     *
     * @return BelongsToMany
     */
    public function countries()
    {
        return $this->belongsToMany(Country::class);
    }

    /**
     * The currencies that belong to the Filter
     *
     * @return BelongsToMany
     */
    public function currencies()
    {
        return $this->belongsToMany(Currency::class);
    }
}
