<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Keyword extends Model
{
    use HasFactory;

    /**
     * The filters that belong to the Keyword
     *
     * @return BelongsToMany
     */
    public function filters()
    {
        return $this->belongsToMany(Filter::class);
    }
}
