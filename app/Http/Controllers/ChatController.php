<?php

namespace App\Http\Controllers;

use App\Models\Thread;
use App\Models\User;
use App\Services\ThreadAssigner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        $threads = Thread::query()
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
            ->orderByRaw('last_client_message_at IS NULL')
            ->orderByDesc('last_client_message_at')
            ->paginate(20)
            ->withQueryString();

        return view('content.pages.chats', ['threads' => $threads]);
    }

    public function detail(Thread $thread)
    {
        $thread->load([
            'assignedUser',
            'proposal',
            'messages' => fn ($q) => $q->orderBy('message_time'),
            'messages.sender',
            'messages.attachments',
            'logs' => fn ($q) => $q->orderBy('created_at'),
            'logs.fromUser',
            'logs.toUser',
        ]);

        // AI matches are never logged; reconstruct the first assignee.
        $firstAssignee = $thread->logs->first()?->fromUser ?? $thread->assignedUser;

        return view('_partials.chat-thread-detail', [
            'thread' => $thread,
            'firstAssignee' => $firstAssignee,
            'mobileUsers' => User::mobile()->orderBy('name')->get(['id', 'name', 'escalation_ladder']),
        ]);
    }

    public function assign(Request $request, Thread $thread)
    {
        $validated = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', 'mobile')],
        ]);

        $to = User::findOrFail($validated['user_id']);

        if ((int) $thread->assigned_user_id !== $to->id) {
            app(ThreadAssigner::class)->assign($thread, $to, ThreadAssigner::TYPE_MANUAL, $thread->assignedUser);
        }

        return response()->json(['success' => true]);
    }
}
