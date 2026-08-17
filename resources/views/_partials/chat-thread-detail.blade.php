{{-- resources/views/_partials/chat-thread-detail.blade.php --}}
@php
    $title = $thread->proposal->title ?? "Project {$thread->project_id}";
@endphp

<div class="p-1">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
        <div>
            <h5 class="mb-1">{{ $title }}</h5>
            <div class="text-dark small">
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
    @if ($thread->blocked)
        <div class="alert alert-danger d-flex justify-content-between align-items-center py-2 px-3 mb-3" role="alert">
            <span>
                <i class="bx bx-block me-1"></i><strong>Blocked</strong>@if ($thread->block_reason) — {{ $thread->block_reason }}@endif
            </span>
            <button type="button" id="chat-unblock-btn" data-thread-id="{{ $thread->id }}" class="btn btn-sm btn-danger text-nowrap ms-3">
                <i class="bx bx-lock-open-alt me-1"></i>Unblock
            </button>
        </div>
    @endif
    <p class="text-dark small mb-3">
        <i class="bx bx-calendar me-1"></i>Created {{ $thread->created_at?->timezone('Asia/Karachi')->format('M j, Y H:i') }}
        @if ($thread->last_client_message_at)
            <span class="mx-1">·</span><i class="bx bx-message-dots me-1"></i>Last client message {{ $thread->last_client_message_at->diffForHumans() }}
        @endif
        @if ($thread->last_escalated_at)
            <span class="mx-1">·</span><i class="bx bx-up-arrow-alt me-1"></i>Last escalated {{ $thread->last_escalated_at->diffForHumans() }}
        @endif
    </p>

    @include('_partials.chat-project-details', ['thread' => $thread, 'insight' => $insight ?? null])

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
                        Assigned to
                    </small>
                </div>
            </div>
            @if (($mobileUsers ?? collect())->isNotEmpty())
                <label class="form-label small text-muted mb-1" for="chat-assign-user">Reassign to</label>
                <div class="d-flex align-items-center gap-2">
                    <div class="flex-grow-1">
                        <select id="chat-assign-user" class="selectpicker" data-style="btn-default bg-white border"
                                data-width="100%" data-thread-id="{{ $thread->id }}"
                                data-current-user-id="{{ $thread->assigned_user_id ?? '' }}">
                            @foreach ($mobileUsers as $user)
                                <option value="{{ $user->id }}" @selected($thread->assigned_user_id === $user->id)>
                                    {{ $user->name }}
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
                $fromLabel = $log->fromUser ? $log->fromUser->name : '—';
                $toLabel = $log->toUser ? $log->toUser->name : '—';
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
    @php
        // Name the person on the other end rather than a generic "Client", and
        // show their avatar, so a long thread reads like a conversation with
        // someone. Falls back when the project owner was never scraped.
        $clientLabel = $insight?->client_name ?: 'Client';
        $clientPic = trim((string) ($insight?->client_avatar ?? ''));
        $clientPic = str_starts_with($clientPic, '//') ? 'https:'.$clientPic : $clientPic;
        $clientPic = (str_starts_with($clientPic, 'https://') || str_starts_with($clientPic, 'http://'))
            ? $clientPic
            : null;
    @endphp
    <h6 class="mb-2">Conversation with {{ $clientLabel }}</h6>
    @forelse ($thread->messages as $message)
        <div class="d-flex mb-3 {{ $message->direction === 'sent' ? 'justify-content-end' : '' }}">
            <div class="rounded p-3 {{ $message->direction === 'sent' ? 'bg-label-primary' : 'bg-lighter' }}" style="max-width: 85%;">
                <div class="small text-muted mb-1">
                    @if ($message->direction === 'received' && $clientPic)
                        <img src="{{ $clientPic }}" alt="" width="16" height="16" class="rounded-circle me-1">
                    @endif
                    {{ $message->direction === 'sent' ? ($message->sent_by_ai ? 'AI Assistant' : ($message->sender?->name ?? 'Owner')) : $clientLabel }}
                    · {{ $message->message_time?->timezone('Asia/Karachi')->format('M j, H:i') }}
                    @if ($message->sent_by_ai)
                        <span class="badge bg-label-info ms-1">AI</span>
                    @endif
                    @if ($message->direction === 'received' && $message->is_read === false)
                        <span class="badge bg-label-warning ms-1">Unread</span>
                    @endif
                </div>
                <div style="white-space: pre-wrap;">{{ $message->message }}</div>
                @foreach ($message->attachments as $attachment)
                    <div class="mt-2 d-flex align-items-center gap-2">
                        @if ($attachment->isStored())
                            <a href="{{ $attachment->serve_url }}" target="_blank" rel="noopener noreferrer">
                                <i class="bx bx-paperclip"></i> {{ $attachment->filename }}
                            </a>
                            <a href="{{ $attachment->download_url }}" class="text-muted" title="Download">
                                <i class="bx bx-download"></i>
                            </a>
                        @else
                            <span class="text-muted"><i class="bx bx-paperclip"></i> {{ $attachment->filename }} <small>(fetching…)</small></span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-muted">No messages yet</p>
    @endforelse

    {{-- Reply from the dashboard instead of the app. Goes out over the same
         SendThreadMessage path the mobile client uses, so the reply appears in
         both places and Freelancer sees one conversation. --}}
    @if ($thread->blocked)
        <p class="text-muted small mb-0">
            <i class="bx bx-block me-1"></i>Unblock this thread to reply.
        </p>
    @else
        <form id="chat-reply-form" class="mt-3" data-thread-id="{{ $thread->id }}">
            <label class="form-label small text-muted mb-1" for="chat-reply-text">Reply as {{ auth()->user()->name }}</label>
            <textarea class="form-control mb-2" id="chat-reply-text" name="message" rows="3"
                      placeholder="Write a reply to the client…"></textarea>
            <div class="d-flex align-items-center gap-2">
                <input type="file" class="form-control form-control-sm" id="chat-reply-files"
                       name="attachments[]" multiple style="max-width: 20rem;">
                <button type="submit" class="btn btn-primary text-nowrap ms-auto" id="chat-reply-btn">
                    <i class="bx bx-send me-1"></i>Send
                </button>
            </div>
            <div class="form-text">Up to 5 attachments, 20 MB each.</div>
        </form>
    @endif
</div>
