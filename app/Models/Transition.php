<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transition extends Model
{
    use HasFactory;

    protected $fillable = ['number'];

    public function users(): HasMany
    {
        return $this->hasMany(TransitionUser::class)->orderBy('position');
    }
}
