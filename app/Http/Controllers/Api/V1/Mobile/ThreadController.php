<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadResource;
use App\Models\Thread;
use App\Models\User;
use App\Services\ThreadAssigner;
use Illuminate\Http\Request;

class ThreadController extends Controller
{
    use RespondsMobile;

    public function index(Request $request)
    {
        $threads = Thread::where('assigned_user_id', $request->user()->id)
            ->where('blocked', $request->boolean('blocked'))
            ->with(['proposal.bid'])
            ->orderByDesc('last_client_message_at')
            ->paginate(50);

        return $this->okPaginated(
            $threads,
            ThreadResource::collection($threads->items()),
            'Threads fetched successfully.'
        );
    }

    public function show(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->load(['proposal.bid']);

        return $this->ok(new ThreadResource($thread), 'Thread fetched successfully.');
    }

    public function block(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->blocked = true;
        $thread->save();

        return $this->ok(['blocked' => true], 'Thread blocked.');
    }

    public function unblock(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->blocked = false;
        $thread->save();

        return $this->ok(['blocked' => false], 'Thread unblocked.');
    }

    public function assign(Request $request, Thread $thread, ThreadAssigner $assigner)
    {
        $this->authorizeThread($request, $thread);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $target = User::find($validated['user_id']);

        if (! $target->isMobile() || $target->id === $request->user()->id) {
            return $this->fail('Target must be another mobile user.', 422, [
                'user_id' => ['Target must be another mobile user.'],
            ]);
        }

        $assigner->assign($thread, $target, ThreadAssigner::TYPE_MANUAL, $request->user());

        return $this->ok(
            new ThreadResource($thread->fresh()->load('proposal.bid')),
            'Thread assigned successfully.'
        );
    }

    private function authorizeThread(Request $request, Thread $thread): void
    {
        abort_unless((int) $thread->assigned_user_id === (int) $request->user()->id, 403);
    }
}
