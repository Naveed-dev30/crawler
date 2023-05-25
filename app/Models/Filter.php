<?php

namespace App\Models;

use App\Models\Country;
use App\Models\Keyword;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Filter extends Model
{
    use HasFactory;

   protected $hidden = [
        'created_at',
        'updated_at',
        'id'
    ];

    /**
     * The countries that belong to the Filter
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function countries()
    {
        return $this->belongsToMany(Country::class);
    }

    /**
     * The currencies that belong to the Filter
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function currencies()
    {
        return $this->belongsToMany(Currency::class);
    }

    /**
     * Get all of the Keywords for the Filter
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function keywords()
    {
        return $this->belongsToMany(Keyword::class);
    }
}
