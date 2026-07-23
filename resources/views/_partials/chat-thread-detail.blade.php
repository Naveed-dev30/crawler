{{-- resources/views/_partials/chat-thread-detail.blade.php --}}
@php
    $title = $thread->proposal->title ?? "Project {$thread->project_id}";
@endphp

<div class="p-1">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
        <div>
            <h5 class="mb-1">{{ $title }}</h5>
            <div class="text-muted small">
                <i class="bx bx-briefcase-alt me-1"></i>Project {{ $thread->project_id }}
            </div>
        </div>
        <div class="text-nowrap">
            <span class="badge {{ $thread->status === 'fresh' ? 'bg-label-warning' : 'bg-label-success' }}">{{ ucfirst($thread->status) }}</span>
            @if ($thread->blocked)
                <span class="badge bg-label-danger">Blocked</span>
            @endif
        </div>
    </div>
    <p class="text-muted small mb-3">
        <i class="bx bx-calendar me-1"></i>Created {{ $thread->created_at?->format('M j, Y H:i') }}
        @if ($thread->last_client_message_at)
            <span class="mx-1">·</span><i class="bx bx-message-dots me-1"></i>Last client message {{ $thread->last_client_message_at->diffForHumans() }}
        @endif
        @if ($thread->last_escalated_at)
            <span class="mx-1">·</span><i class="bx bx-up-arrow-alt me-1"></i>Last escalated {{ $thread->last_escalated_at->diffForHumans() }}
        @endif
    </p>

    {{-- Assignee card --}}
    @php
        $assigneeName = $thread->assignedUser->name ?? null;
        $assigneeInitials = $assigneeName
            ? collect(explode(' ', $assigneeName))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('')
            : '?';
    @endphp
    <div class="card shadow-none border mb-4">
        <div class="card-body p-3">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div class="avatar avatar-sm flex-shrink-0">
                    <span class="avatar-initial rounded-circle {{ $assigneeName ? 'bg-label-primary' : 'bg-label-secondary' }}">{{ $assigneeInitials }}</span>
                </div>
                <div>
                    <div class="fw-semibold lh-sm">{{ $assigneeName ?? 'Unassigned' }}</div>
                    <small class="text-muted">
                        Assigned to{{ $thread->assignedUser?->escalation_ladder !== null && $assigneeName ? " · ladder {$thread->assignedUser->escalation_ladder}" : '' }}
                    </small>
                </div>
            </div>
            @if (($mobileUsers ?? collect())->isNotEmpty())
                <label class="form-label small text-muted mb-1" for="chat-assign-user">Reassign to</label>
                <div class="d-flex align-items-center gap-2">
                    <div class="flex-grow-1">
                        <select id="chat-assign-user" class="selectpicker" data-style="btn-default bg-white border"
                                data-width="100%" data-thread-id="{{ $thread->id }}">
                            @foreach ($mobileUsers as $user)
                                <option value="{{ $user->id }}" @selected($thread->assigned_user_id === $user->id)>
                                    {{ $user->name }}{{ $user->escalation_ladder !== null ? " — ladder {$user->escalation_ladder}" : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" id="chat-assign-btn" class="btn btn-primary text-nowrap">
                        <i class="bx bx-user-plus me-1"></i>Assign
                    </button>
                </div>
            @endif
        </div>
    </div>

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
            @php
                $fromLabel = $log->fromUser
                    ? $log->fromUser->name . ($log->fromUser->escalation_ladder !== null ? " (ladder {$log->fromUser->escalation_ladder})" : '')
                    : '—';
                $toLabel = $log->toUser
                    ? $log->toUser->name . ($log->toUser->escalation_ladder !== null ? " (ladder {$log->toUser->escalation_ladder})" : '')
                    : '—';
            @endphp
            <li class="mb-2">
                @if ($log->type === 'escalation')
                    <i class="bx bx-up-arrow-alt text-danger me-1"></i>
                    Escalated: {{ $fromLabel }} → {{ $toLabel }}
                    <small class="text-muted d-block ms-4">
                        No reply within the escalation window · {{ $log->created_at?->format('M j, Y H:i') }}
                    </small>
                @else
                    <i class="bx bx-transfer text-primary me-1"></i>
                    Reassigned: {{ $fromLabel }} → {{ $toLabel }}
                    <small class="text-muted d-block ms-4">{{ $log->created_at?->format('M j, Y H:i') }}</small>
                @endif
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
                        @if (\Illuminate\Support\Str::startsWith((string) $attachment->url, ['http://', 'https://']))
                            <a href="{{ $attachment->url }}" target="_blank" rel="noopener noreferrer">
                                <i class="bx bx-paperclip"></i> {{ $attachment->filename }}
                            </a>
                        @else
                            <span class="text-muted"><i class="bx bx-paperclip"></i> {{ $attachment->filename }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-muted">No messages yet</p>
    @endforelse
</div>
