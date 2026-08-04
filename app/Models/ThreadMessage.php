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
        'sent_by_ai',
    ];

    protected $casts = [
        'message_time' => 'datetime',
        'is_read' => 'boolean',
        'sent_by_ai' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Keep threads.last_message_at authoritative regardless of which path
        // inserted the message (real sync, app/AI send, or the fake simulator).
        // This is what the Chats list + mobile thread list sort by, so the
        // thread bubbles up on the newest activity in either direction.
        static::created(function (ThreadMessage $message) {
            if (! $message->thread_id || ! $message->message_time) {
                return;
            }

            Thread::where('id', $message->thread_id)
                ->where(function ($q) use ($message) {
                    $q->whereNull('last_message_at')
                        ->orWhere('last_message_at', '<', $message->message_time);
                })
                ->update(['last_message_at' => $message->message_time]);
        });
    }

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
