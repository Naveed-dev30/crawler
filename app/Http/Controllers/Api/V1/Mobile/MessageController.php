<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadMessageResource;
use App\Jobs\MarkThreadReadJob;
use App\Models\Thread;
use App\Services\SendThreadMessage;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use RespondsMobile;

    public function index(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        // Opening the conversation counts as reading it on Freelancer.
        MarkThreadReadJob::dispatch($thread->id);

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

    public function store(Request $request, Thread $thread, SendThreadMessage $sender)
    {
        $this->authorizeThread($request, $thread);

        if ($thread->blocked) {
            return $this->fail('Thread is blocked; unblock before sending.', 409);
        }

        $validated = $request->validate([
            'message' => 'nullable|string|required_without:attachments',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480', // 20 MB each
        ]);

        $stored = $sender->send(
            $thread,
            $validated['message'] ?? null,
            $request->file('attachments', []),
            $request->user()->id,
            false
        );

        if ($stored === null) {
            return $this->fail('Freelancer rejected the message.', 502);
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
