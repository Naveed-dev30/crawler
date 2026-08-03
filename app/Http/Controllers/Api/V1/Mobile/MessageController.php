<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadMessageResource;
use App\Jobs\MarkThreadReadJob;
use App\Models\Thread;
use App\Services\FreelancerMessenger;
use App\Services\SendThreadMessage;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use RespondsMobile;

    public function index(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        // Opening the conversation counts as reading it on Freelancer. Only on
        // the first page: paging back through history fired this per page,
        // hitting the Freelancer API and re-broadcasting each time.
        if ($request->integer('page', 1) <= 1) {
            MarkThreadReadJob::dispatch($thread->id);
        }

        // Newest first, so page 1 is the end of the conversation. Ordering
        // ascending made page 1 the OLDEST 200 messages, which opened a long
        // thread at the wrong end — and since the client never paged forward,
        // its recent messages were unreachable entirely.
        //
        // The client reverses each page for display and prepends older pages.
        // `id` breaks ties: imported messages can share a message_time, and an
        // unstable sort would duplicate or drop rows across page boundaries.
        $messages = $thread->messages()
            ->with('attachments')
            ->orderByDesc('message_time')
            ->orderByDesc('id')
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

    /**
     * Relay a typing signal to the client on Freelancer while the mobile user
     * composes. Fire-and-forget: the response is always 200 so an outbound
     * hiccup never interrupts the compose UX; failures are logged server-side.
     */
    public function typing(Request $request, Thread $thread, FreelancerMessenger $messenger)
    {
        $this->authorizeThread($request, $thread);

        $messenger->sendTyping((int) $thread->freelancer_thread_id);

        return $this->ok(null, 'Typing signal sent.');
    }

    private function authorizeThread(Request $request, Thread $thread): void
    {
        abort_unless((int) $thread->assigned_user_id === (int) $request->user()->id, 403);
    }
}
