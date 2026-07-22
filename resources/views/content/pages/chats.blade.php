{{-- resources/views/content/pages/chats.blade.php --}}
@extends('layouts.layoutMaster')

@section('title', 'Chats')

@section('content')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <h5 class="mb-0">Chats</h5>
        </div>
        <div class="table-responsive text-nowrap">
            <table class="table table-hover">
                <thead>
                <tr>
                    <th>Project</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th>Messages</th>
                    <th>Escalations</th>
                    <th>Last Client Message</th>
                    <th></th>
                </tr>
                </thead>
                <tbody class="table-border-bottom-0">
                @forelse ($threads as $thread)
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $thread->project_id }}</span>
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
                        <td>{{ $thread->last_client_message_at?->diffForHumans() ?? '—' }}</td>
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
                </tbody>
            </table>
        </div>
    </div>

    @if ($threads->hasPages())
        <div class="mt-4 card px-4 pt-3">
            {{ $threads->links('vendor.pagination.bootstrap-5') }}
        </div>
    @endif
@endsection
