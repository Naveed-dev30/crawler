<?php

namespace App\Http\Controllers;

use App\Models\BidInsight;
use App\Models\Thread;
use App\Models\User;
use App\Services\SendThreadMessage;
use App\Services\ThreadAssigner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        return view('content.pages.chats', ['threads' => $this->threadsQuery($request)]);
    }

    /**
     * Rows-only endpoint the Chats page polls so the list re-sorts live (newest
     * activity floats up) without a full page refresh.
     */
    public function rows(Request $request)
    {
        $threads = $this->threadsQuery($request);

        return response()->json([
            'rowsHtml' => view('_partials.chat-rows', ['threads' => $threads])->render(),
        ]);
    }

    private function threadsQuery(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        return Thread::query()
            ->with(['assignedUser', 'proposal'])
            ->withCount([
                'messages',
                'logs as escalations_count' => fn ($q) => $q->where('type', 'escalation'),
            ])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('project_id', 'like', "%{$search}%")
                        ->orWhereHas('proposal', fn ($p) => $p->where('title', 'like', "%{$search}%"))
                        ->orWhereHas('assignedUser', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($status, ['fresh', 'answered'], true), fn ($q) => $q->where('status', $status))
            ->when($status === 'blocked', fn ($q) => $q->where('blocked', true))
            // A mobile agent signing in to the dashboard gets their own queue
            // and nothing else; admins see every thread.
            ->when($request->user()->isMobile(), fn ($q) => $q->where('assigned_user_id', $request->user()->id))
            // Newest activity in either direction (our replies included) floats
            // the thread to the top; fall back to inbound, then creation time.
            ->orderByRaw('COALESCE(last_message_at, last_client_message_at, created_at) DESC')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Guard a single thread. The route lets mobile agents in, but only to the
     * threads assigned to them — otherwise any agent could read, reply to, or
     * reassign a colleague's conversation by editing the id in the URL.
     */
    private function authorizeThread(Request $request, Thread $thread): void
    {
        $user = $request->user();

        abort_if($user->isMobile() && (int) $thread->assigned_user_id !== (int) $user->id, 403);
    }

    public function detail(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->load([
            'assignedUser',
            'proposal',
            // Powers the project panel: the listing, our bid on it, and what we
            // scraped about the client.
            'proposal.bid',
            'messages' => fn ($q) => $q->orderBy('message_time'),
            'messages.sender',
            'messages.attachments',
            'logs' => fn ($q) => $q->orderBy('created_at'),
            'logs.fromUser',
            'logs.toUser',
        ]);

        // AI matches are never logged; reconstruct the first assignee.
        $firstAssignee = $thread->logs->first()?->fromUser ?? $thread->assignedUser;

        // Optional — only projects the bid-insights scraper has visited have one.
        $insight = BidInsight::where('project_id', $thread->project_id)
            ->latest('last_scraped_at')
            ->first();

        return view('_partials.chat-thread-detail', [
            'thread' => $thread,
            'insight' => $insight,
            'firstAssignee' => $firstAssignee,
            'mobileUsers' => User::mobile()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function assign(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $validated = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', 'mobile')],
        ]);

        $to = User::findOrFail($validated['user_id']);

        if ((int) $thread->assigned_user_id !== $to->id) {
            app(ThreadAssigner::class)->assign($thread, $to, ThreadAssigner::TYPE_MANUAL, $thread->assignedUser);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Reply to the client from the dashboard. Deliberately the same path the
     * mobile app uses — SendThreadMessage → Freelancer → stored + broadcast —
     * so a reply typed here lands in the app's thread and vice versa.
     *
     * Admins may reply to any thread — covering for an agent who is away is the
     * whole reason to reply from here. Mobile agents are held to their own
     * threads by authorizeThread(), matching the mobile API.
     */
    public function sendMessage(Request $request, Thread $thread, SendThreadMessage $sender)
    {
        $this->authorizeThread($request, $thread);

        if ($thread->blocked) {
            return response()->json([
                'success' => false,
                'message' => 'Thread is blocked; unblock before sending.',
            ], 409);
        }

        // Same limits as the mobile endpoint, so neither surface accepts a
        // message the other would reject.
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
        ]);

        // The composer always posts a message field, so an attachment-only
        // reply arrives as "" — store it as a genuinely empty message rather
        // than a blank string, matching what the mobile client produces.
        $text = trim((string) ($validated['message'] ?? '')) === '' ? null : $validated['message'];

        $stored = $sender->send(
            $thread,
            $text,
            $request->file('attachments', []),
            $request->user()->id,
            false
        );

        if ($stored === null) {
            return response()->json([
                'success' => false,
                'message' => 'Freelancer rejected the message.',
            ], 502);
        }

        return response()->json(['success' => true]);
    }

    public function unblock(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->blocked = false;
        $thread->block_reason = null;
        $thread->save();

        return response()->json(['success' => true]);
    }
}
