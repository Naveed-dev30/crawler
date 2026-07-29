<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransitionUser extends Model
{
    use HasFactory;

    protected $fillable = ['transition_id', 'user_id', 'position'];

    public function transition(): BelongsTo
    {
        return $this->belongsTo(Transition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
