<?php

namespace App\Http\Controllers;

use App\Models\ThreadAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Stream a mirrored attachment. Reached via a temporary signed URL (see
     * ThreadAttachment::getServeUrlAttribute) so both the admin browser and the
     * mobile app can open it without a shared auth guard — the signature is the
     * authorization. `?download=1` forces a download; otherwise it renders
     * inline so images/PDFs preview in the browser.
     */
    public function show(Request $request, ThreadAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->stored_path !== null, 404);

        $disk = Storage::disk($attachment->stored_disk ?? 'local');
        abort_unless($disk->exists($attachment->stored_path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response(
            $attachment->stored_path,
            $attachment->filename,
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => $disposition.'; filename="'.addslashes($attachment->filename).'"',
            ]
        );
    }
}
