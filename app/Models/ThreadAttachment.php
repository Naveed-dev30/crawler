<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ThreadAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_message_id',
        'freelancer_attachment_id',
        'filename',
        'url',
        'mime_type',
        'size',
    ];

    public function message()
    {
        return $this->belongsTo(ThreadMessage::class, 'thread_message_id');
    }
}
