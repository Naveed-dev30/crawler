<?php

namespace App\Http\Controllers;

use App\Models\Thread;
use Illuminate\Http\Request;

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
            ->when(in_array($status, ['fresh', 'replied'], true), fn ($q) => $q->where('status', $status))
            ->when($status === 'blocked', fn ($q) => $q->where('blocked', true))
            ->orderByRaw('last_client_message_at IS NULL')
            ->orderByDesc('last_client_message_at')
            ->paginate(20)
            ->withQueryString();

        return view('content.pages.chats', ['threads' => $threads]);
    }
}
