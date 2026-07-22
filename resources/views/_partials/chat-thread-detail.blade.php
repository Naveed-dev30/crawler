{{-- resources/views/_partials/chat-thread-detail.blade.php --}}
@php($title = $thread->proposal->title ?? "Project {$thread->project_id}")

<div class="p-1">
    {{-- Header --}}
    <h5 class="mb-1">{{ $title }}</h5>
    <p class="text-muted mb-2">Project {{ $thread->project_id }}</p>
    <p class="mb-1">
        <span class="badge {{ $thread->status === 'fresh' ? 'bg-label-warning' : 'bg-label-success' }}">{{ ucfirst($thread->status) }}</span>
        @if ($thread->blocked)
            <span class="badge bg-label-danger">Blocked</span>
        @endif
    </p>
    <p class="mb-1">
        <span class="text-muted">Assigned to:</span>
        @if ($thread->assignedUser)
            <span class="fw-semibold">{{ $thread->assignedUser->name }}</span>
            @if ($thread->assignedUser->escalation_ladder !== null)
                <small class="text-muted">— ladder {{ $thread->assignedUser->escalation_ladder }}</small>
            @endif
        @else
            <span class="text-muted">Unassigned</span>
        @endif
    </p>
    <p class="text-muted small mb-4">
        Created {{ $thread->created_at?->format('M j, Y H:i') }}
        @if ($thread->last_client_message_at)
            · Last client message {{ $thread->last_client_message_at->diffForHumans() }}
        @endif
        @if ($thread->last_escalated_at)
            · Last escalated {{ $thread->last_escalated_at->diffForHumans() }}
        @endif
    </p>

    {{-- Assignment timeline --}}
    <h6 class="mb-2">Assignment History</h6>
    <ul class="list-unstyled mb-4">
        @if ($firstAssignee)
            <li class="mb-2">
                <i class="bx bx-bot text-info me-1"></i>
                AI matched to {{ $firstAssignee->name }}
                <small class="text-muted d-block ms-4">{{ $thread->created_at?->format('M j, Y H:i') }}</small>
            </li>
        @endif
        @foreach ($thread->logs as $log)
            <li class="mb-2">
                @if ($log->type === 'escalation')
                    <i class="bx bx-up-arrow-alt text-danger me-1"></i>
                    Escalated: {{ $log->fromUser?->name ?? '—' }} → {{ $log->toUser?->name ?? '—' }}
                @else
                    <i class="bx bx-transfer text-primary me-1"></i>
                    Reassigned: {{ $log->fromUser?->name ?? '—' }} → {{ $log->toUser?->name ?? '—' }}
                @endif
                <small class="text-muted d-block ms-4">{{ $log->created_at?->format('M j, Y H:i') }}</small>
            </li>
        @endforeach
        @if (! $firstAssignee && $thread->logs->isEmpty())
            <li class="text-muted">No assignment yet</li>
        @endif
    </ul>

    {{-- Conversation --}}
    <h6 class="mb-2">Conversation</h6>
    @forelse ($thread->messages as $message)
        <div class="d-flex mb-3 {{ $message->direction === 'sent' ? 'justify-content-end' : '' }}">
            <div class="rounded p-3 {{ $message->direction === 'sent' ? 'bg-label-primary' : 'bg-lighter' }}" style="max-width: 85%;">
                <div class="small text-muted mb-1">
                    {{ $message->direction === 'sent' ? ($message->sender?->name ?? 'Us') : 'Client' }}
                    · {{ $message->message_time?->format('M j, H:i') }}
                </div>
                <div style="white-space: pre-wrap;">{{ $message->message }}</div>
                @foreach ($message->attachments as $attachment)
                    <div class="mt-2">
                        <a href="{{ $attachment->url }}" target="_blank" rel="noopener">
                            <i class="bx bx-paperclip"></i> {{ $attachment->filename }}
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-muted">No messages yet</p>
    @endforelse
</div>
