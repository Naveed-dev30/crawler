{{-- resources/views/content/pages/chats.blade.php --}}
@extends('layouts.layoutMaster')

@section('title', 'Chats')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.css') }}"/>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title">Chats</h4>

    <div class="card">
        <div class="card-header d-flex justify-content-end align-items-center flex-wrap gap-3">
            <form method="GET" action="{{ route('chats') }}" id="chats-filter-form"
                  class="d-flex align-items-center gap-2">
                <input type="search" class="form-control" name="search" id="chats-search"
                       placeholder="Search project, title or user…" value="{{ request('search') }}"
                       style="min-width: 240px;">
                <select class="selectpicker" data-style="btn-default" data-width="140px"
                        name="status" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="fresh"@selected(request('status') === 'fresh')>Fresh</option>
                    <option value="answered"@selected(request('status') === 'answered')>Answered</option>
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

    {{-- Left slide-over, bids-page behaviour: no backdrop so the table stays clickable; scrollable body --}}
    <div class="offcanvas offcanvas-start" tabindex="-1" id="chatOffcanvas" data-bs-backdrop="false" data-bs-scroll="true"
         style="width: 40rem; max-width: 95vw;">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title">Thread Detail</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body" id="chatOffcanvasContent">
            <p class="text-muted">Loading…</p>
        </div>
        {{-- Long conversations: jump to top / latest without manual scrolling --}}
        <div class="position-absolute d-flex flex-column gap-2" style="right: 1.25rem; bottom: 1.25rem; z-index: 5;">
            <button type="button" class="btn btn-primary btn-icon rounded-circle shadow d-none" id="chatScrollTop"
                    title="Back to top" aria-label="Scroll to top">
                <i class="bx bx-chevrons-up"></i>
            </button>
            <button type="button" class="btn btn-primary btn-icon rounded-circle shadow d-none" id="chatScrollBottom"
                    title="Latest messages" aria-label="Scroll to latest messages">
                <i class="bx bx-chevrons-down"></i>
            </button>
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

            const ocBody = document.getElementById('chatOffcanvasContent');
            const topBtn = document.getElementById('chatScrollTop');
            const bottomBtn = document.getElementById('chatScrollBottom');

            // One button at a time: lower half of the scroll → jump up, upper half → jump down.
            const updateScrollButtons = () => {
                const max = ocBody.scrollHeight - ocBody.clientHeight;
                const canScroll = max > 50;
                const showUp = canScroll && ocBody.scrollTop > max / 2;
                topBtn.classList.toggle('d-none', !showUp);
                bottomBtn.classList.toggle('d-none', !canScroll || showUp);
            };
            ocBody.addEventListener('scroll', updateScrollButtons);
            topBtn.addEventListener('click', () => ocBody.scrollTo({ top: 0, behavior: 'smooth' }));
            bottomBtn.addEventListener('click', () => ocBody.scrollTo({ top: ocBody.scrollHeight, behavior: 'smooth' }));

            const loadDetail = async (threadId) => {
                const res = await fetch('/chats/' + threadId + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                ocBody.innerHTML = res.ok ? await res.text() : '<p class="text-danger">Failed to load thread</p>';
                // The assign select arrives with the partial — init bootstrap-select for the white menu.
                if (window.jQuery && jQuery.fn.selectpicker) jQuery('#chat-assign-user').selectpicker();
                syncAssignButton();
                updateScrollButtons();
            };

            // Assign is a no-op while the selection matches the current assignee — keep it disabled.
            const syncAssignButton = () => {
                const select = document.getElementById('chat-assign-user');
                const btn = document.getElementById('chat-assign-btn');
                if (select && btn) btn.disabled = select.value === select.dataset.currentUserId;
            };
            ocBody.addEventListener('change', (e) => {
                if (e.target.id === 'chat-assign-user') syncAssignButton();
            });

            document.querySelectorAll('.js-chat-view').forEach(btn => btn.addEventListener('click', async () => {
                ocBody.innerHTML = '<p class="text-muted">Loading…</p>';
                bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('chatOffcanvas')).show();
                await loadDetail(btn.dataset.threadId);
                ocBody.scrollTop = 0;
            }));

            // Manual assign: delegated — the control lives inside the fetched partial.
            ocBody.addEventListener('click', async (e) => {
                const btn = e.target.closest('#chat-assign-btn');
                if (!btn) return;
                const select = document.getElementById('chat-assign-user');
                if (select.value === select.dataset.currentUserId) return; // already assigned — nothing to do
                btn.disabled = true;
                try {
                    const res = await fetch('/chats/' + select.dataset.threadId + '/assign', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ user_id: select.value }),
                    });
                    if (!res.ok) throw new Error();
                    const name = select.options[select.selectedIndex].text.split(' — ')[0].trim();
                    await loadDetail(select.dataset.threadId);
                    // Sync the server-rendered "Assigned To" cell without losing the open panel.
                    const rowBtn = document.querySelector('.js-chat-view[data-thread-id="' + select.dataset.threadId + '"]');
                    if (rowBtn) rowBtn.closest('tr').cells[1].textContent = name;
                } catch {
                    btn.disabled = false;
                    alert('Failed to assign thread');
                }
            });
        });
    </script>
@endsection
