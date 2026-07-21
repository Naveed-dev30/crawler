<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadResource;
use App\Models\Thread;
use App\Models\User;
use App\Services\ThreadAssigner;
use Illuminate\Http\Request;

class ThreadController extends Controller
{
    public function index(Request $request)
    {
        $threads = Thread::where('assigned_user_id', $request->user()->id)
            ->where('blocked', $request->boolean('blocked'))
            ->with(['proposal.bid'])
            ->orderByDesc('last_client_message_at')
            ->paginate(50);

        return ThreadResource::collection($threads);
    }

    public function show(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->load(['proposal.bid']);

        return new ThreadResource($thread);
    }

    public function block(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->blocked = true;
        $thread->save();

        return response()->json(['blocked' => true]);
    }

    public function unblock(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->blocked = false;
        $thread->save();

        return response()->json(['blocked' => false]);
    }

    public function assign(Request $request, Thread $thread, ThreadAssigner $assigner)
    {
        $this->authorizeThread($request, $thread);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $target = User::find($validated['user_id']);

        if (! $target->isMobile() || $target->id === $request->user()->id) {
            return response()->json([
                'message' => 'Target must be another mobile user.',
                'errors' => ['user_id' => ['Target must be another mobile user.']],
            ], 422);
        }

        $assigner->assign($thread, $target, ThreadAssigner::TYPE_MANUAL, $request->user());

        return new ThreadResource($thread->fresh()->load('proposal.bid'));
    }

    private function authorizeThread(Request $request, Thread $thread): void
    {
        abort_unless((int) $thread->assigned_user_id === (int) $request->user()->id, 403);
    }
}
