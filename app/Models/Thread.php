<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Thread extends Model
{
    use HasFactory;

    protected $fillable = [
        'freelancer_thread_id',
        'project_id',
        'proposal_id',
        'assigned_user_id',
        'status',
        'blocked',
        'last_client_message_at',
        'last_escalated_at',
        'freelancer_time_updated',
    ];

    protected $casts = [
        'blocked' => 'boolean',
        'last_client_message_at' => 'datetime',
        'last_escalated_at' => 'datetime',
    ];

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages()
    {
        return $this->hasMany(ThreadMessage::class);
    }

    // No scopeFresh: it would collide with Eloquent's Model::fresh().
    public function scopeUnblocked($query)
    {
        return $query->where('blocked', false);
    }
}
