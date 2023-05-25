<?php

namespace App\Models;

use App\Models\Filter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Keyword extends Model
{
    use HasFactory;

    /**
     * The filters that belong to the Keyword
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function filters()
    {
        return $this->belongsToMany(Filter::class);
    }
}
