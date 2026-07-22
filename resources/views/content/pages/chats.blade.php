{{-- resources/views/content/pages/chats.blade.php --}}
@extends('layouts.layoutMaster')

@section('title', 'Chats')

@section('content')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <h5 class="mb-0">Chats</h5>
            <form method="GET" action="{{ route('chats') }}" id="chats-filter-form"
                  class="d-flex align-items-center gap-2">
                <input type="search" class="form-control" name="search" id="chats-search"
                       placeholder="Search project, title or user…" value="{{ request('search') }}"
                       style="min-width: 240px;">
                <select class="form-select" name="status" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="fresh"@selected(request('status') === 'fresh')>Fresh</option>
                    <option value="replied"@selected(request('status') === 'replied')>Replied</option>
                    <option value="blocked"@selected(request('status') === 'blocked')>Blocked</option>
                </select>
            </form>
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

    <div class="offcanvas offcanvas-end" tabindex="-1" id="chatOffcanvas" style="width: 480px;">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title">Thread Detail</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body" id="chatOffcanvasContent">
            <p class="text-muted">Loading…</p>
        </div>
    </div>
@endsection

@section('page-script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('chats-search');
            let timer;
            searchInput.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => document.getElementById('chats-filter-form').submit(), 400);
            });

            document.querySelectorAll('.js-chat-view').forEach(btn => btn.addEventListener('click', async () => {
                const body = document.getElementById('chatOffcanvasContent');
                body.innerHTML = '<p class="text-muted">Loading…</p>';
                bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('chatOffcanvas')).show();
                const res = await fetch('/chats/' + btn.dataset.threadId + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                body.innerHTML = res.ok ? await res.text() : '<p class="text-danger">Failed to load thread</p>';
            }));
        });
    </script>
@endsection
