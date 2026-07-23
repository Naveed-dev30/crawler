<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadMessageResource;
use App\Models\Thread;
use App\Services\FreelancerMessenger;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use RespondsMobile;

    public function index(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        // Opening the conversation counts as reading it on Freelancer.
        \App\Jobs\MarkThreadReadJob::dispatch($thread->id);

        $messages = $thread->messages()
            ->with('attachments')
            ->orderBy('message_time')
            ->paginate(200);

        return $this->okPaginated(
            $messages,
            ThreadMessageResource::collection($messages->items()),
            'Messages fetched successfully.'
        );
    }

    public function store(Request $request, Thread $thread, FreelancerMessenger $messenger)
    {
        $this->authorizeThread($request, $thread);

        $validated = $request->validate([
            'message' => 'nullable|string|required_without:attachments',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480', // 20 MB each
        ]);

        $text = $validated['message'] ?? null;
        $files = $request->file('attachments', []);

        $result = $messenger->sendMessage((int) $thread->freelancer_thread_id, $text, $files);

        if ($result === null) {
            return $this->fail('Freelancer rejected the message.', 502);
        }

        $stored = $thread->messages()->create([
            'freelancer_message_id' => $result['id'] ?? null,
            'direction' => 'sent',
            'sender_user_id' => $request->user()->id,
            'message' => $text,
            'message_time' => now(),
        ]);

        foreach ($files as $file) {
            $stored->attachments()->create([
                'filename' => $file->getClientOriginalName(),
                'url' => '', // outbound attachment; content lives on Freelancer
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        if ($thread->status === 'fresh') {
            $thread->status = 'answered';
            $thread->save();
        }

        return $this->ok(
            new ThreadMessageResource($stored->load('attachments')),
            'Message sent successfully.',
            201
        );
    }

    private function authorizeThread(Request $request, Thread $thread): void
    {
        abort_unless((int) $thread->assigned_user_id === (int) $request->user()->id, 403);
    }
}
