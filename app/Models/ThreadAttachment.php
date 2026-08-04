<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class ThreadAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_message_id',
        'freelancer_attachment_id',
        'filename',
        'url',
        'stored_path',
        'stored_disk',
        'mime_type',
        'size',
    ];

    public function message()
    {
        return $this->belongsTo(ThreadMessage::class, 'thread_message_id');
    }

    /**
     * Whether a local copy of the file has been mirrored and can be served
     * through our authed route.
     */
    public function isStored(): bool
    {
        return $this->stored_path !== null;
    }

    /**
     * The URL clients (admin browser + mobile) should use. Points at our authed
     * streaming route once the file is mirrored locally; null while it's still
     * pending download (or was outbound-only with no bytes yet).
     */
    public function getServeUrlAttribute(): ?string
    {
        if (! $this->isStored()) {
            return null;
        }

        return URL::temporarySignedRoute('attachments.show', now()->addDay(), ['attachment' => $this->id]);
    }

    /**
     * Same file, forced as a download rather than inline preview.
     */
    public function getDownloadUrlAttribute(): ?string
    {
        if (! $this->isStored()) {
            return null;
        }

        return URL::temporarySignedRoute('attachments.show', now()->addDay(), [
            'attachment' => $this->id,
            'download' => 1,
        ]);
    }
}
