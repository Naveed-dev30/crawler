<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadResource;
use App\Models\ActivityLog;
use App\Models\BidInsight;
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
        $thread->setAttribute(
            'client_insight',
            BidInsight::where('project_id', $thread->project_id)->first()
        );

        return $this->ok(new ThreadResource($thread), 'Thread fetched successfully.');
    }

    public function block(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $thread->blocked = true;
        $thread->block_reason = $validated['reason'];
        $thread->save();

        ActivityLog::create([
            'thread_id' => $thread->id,
            'from_user_id' => $request->user()->id,
            'to_user_id' => $thread->assigned_user_id,
            'type' => 'block',
            'message' => "thread {$thread->project_id} blocked by user({$request->user()->name}): {$thread->block_reason}",
        ]);

        return $this->ok(['blocked' => true, 'reason' => $thread->block_reason], 'Thread blocked.');
    }

    public function unblock(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->blocked = false;
        $thread->block_reason = null;
        $thread->save();

        ActivityLog::create([
            'thread_id' => $thread->id,
            'from_user_id' => $request->user()->id,
            'to_user_id' => $thread->assigned_user_id,
            'type' => 'unblock',
            'message' => "thread {$thread->project_id} unblocked by user({$request->user()->name})",
        ]);

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
