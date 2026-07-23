<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ThreadMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'freelancer_message_id',
        'direction',
        'from_freelancer_user_id',
        'sender_user_id',
        'message',
        'message_time',
        'is_read',
    ];

    protected $casts = [
        'message_time' => 'datetime',
        'is_read' => 'boolean',
    ];

    public function thread()
    {
        return $this->belongsTo(Thread::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments()
    {
        return $this->hasMany(ThreadAttachment::class);
    }
}
