@forelse ($threads as $thread)
    <tr>
        <td>
            <span class="fw-semibold">{{ $thread->project_id }}</span>
            {{-- Jump to the project's own detail panel on Opportunities. Hidden
                 from mobile agents, who are chat-only and would just be bounced
                 back here. --}}
            @if (! auth()->user()->isMobile())
                <a href="{{ route('bids', ['q' => $thread->project_id, 'open' => 1]) }}"
                   target="_blank" rel="noopener"
                   class="ms-1 text-muted js-project-link" title="Open project details">
                    <i class="bx bx-link-external"></i>
                </a>
            @endif
            @if ($thread->proposal?->title)
                <br><small class="text-muted">{{ \Illuminate\Support\Str::limit($thread->proposal->title, 45) }}</small>
            @endif
        </td>
        <td>
            @if ($thread->assignedUser)
                {{ $thread->assignedUser->name }}
            @else
                <span class="text-muted">Unassigned</span>
            @endif
        </td>
        <td>
            <span class="badge {{ $thread->status === 'fresh' ? 'bg-label-warning' : 'bg-label-success' }}">{{ ucfirst($thread->status) }}</span>
            @if ($thread->blocked)
                <span class="badge bg-label-danger">Blocked</span>
            @endif
        </td>
        <td>{{ $thread->messages_count }}</td>
        <td>{{ $thread->escalations_count }}</td>
        <td>{{ ($thread->last_message_at ?? $thread->last_client_message_at)?->diffForHumans() ?? '—' }}</td>
        <td>
            <button type="button" class="btn btn-sm btn-label-primary js-chat-view" data-thread-id="{{ $thread->id }}">
                <i class="bx bx-show me-1"></i>View
            </button>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center text-muted py-4">No chat threads yet</td>
    </tr>
@endforelse
