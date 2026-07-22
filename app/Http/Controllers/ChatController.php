<?php
// app/Http/Controllers/ChatController.php

namespace App\Http\Controllers;

use App\Models\Thread;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $threads = Thread::query()
            ->with(['assignedUser', 'proposal'])
            ->withCount([
                'messages',
                'logs as escalations_count' => fn ($q) => $q->where('type', 'escalation'),
            ])
            ->orderByRaw('last_client_message_at IS NULL')
            ->orderByDesc('last_client_message_at')
            ->paginate(20)
            ->withQueryString();

        return view('content.pages.chats', ['threads' => $threads]);
    }
}
