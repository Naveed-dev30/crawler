<?php

namespace App\Jobs;

use App\Models\ThreadAttachment;
use App\Services\FreelancerMessenger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Mirror a received attachment's bytes from Freelancer onto our private disk so
 * the admin browser and mobile app can view/download it through our authed
 * (signed) route, instead of hitting Freelancer's OAuth-gated URL directly.
 */
class DownloadThreadAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DISK = 'local';

    public function __construct(public int $attachmentId) {}

    public function handle(FreelancerMessenger $messenger): void
    {
        $attachment = ThreadAttachment::find($this->attachmentId);

        // Already mirrored, or nothing to fetch from.
        if (! $attachment || $attachment->stored_path !== null || ! $attachment->url) {
            return;
        }

        $result = $messenger->downloadAttachment($attachment->url);
        if ($result === null) {
            return; // transient; a later sync/retry can re-dispatch
        }

        $ext = pathinfo($attachment->filename, PATHINFO_EXTENSION);
        $path = 'thread-attachments/'.$attachment->id.($ext !== '' ? '.'.$ext : '');

        Storage::disk(self::DISK)->put($path, $result['body']);

        $attachment->stored_path = $path;
        $attachment->stored_disk = self::DISK;
        if (! $attachment->mime_type && $result['mime']) {
            $attachment->mime_type = $result['mime'];
        }
        if (! $attachment->size) {
            $attachment->size = strlen($result['body']);
        }
        $attachment->save();
    }
}
