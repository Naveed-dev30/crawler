{{-- resources/views/content/pages/chats.blade.php --}}
@extends('layouts.layoutMaster')

@section('title', 'Chats')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/sweetalert2/sweetalert2.css') }}"/>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/pusher/pusher.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/sweetalert2/sweetalert2.js') }}"></script>
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
                    <th>Last Message</th>
                    <th></th>
                </tr>
                </thead>
                <tbody class="table-border-bottom-0" id="chats-tbody">
                @include('_partials.chat-rows', ['threads' => $threads])
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
    @include('_partials.toast-helper')
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
                // A live refresh can land while the admin is mid-reply; carry the
                // draft across the re-render so an incoming client message never
                // eats what was being typed.
                const draft = document.getElementById('chat-reply-text')?.value ?? '';
                // Same reasoning for the expanded project text: a refresh must not
                // snap it shut while it is being read.
                const projectOpen = document.getElementById('chat-project-more')?.classList.contains('show');
                const res = await fetch('/chats/' + threadId + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                ocBody.innerHTML = res.ok ? await res.text() : '<p class="text-danger">Failed to load thread</p>';
                const replyBox = document.getElementById('chat-reply-text');
                if (replyBox && draft) replyBox.value = draft;
                if (projectOpen) {
                    document.getElementById('chat-project-more')?.classList.add('show');
                    document.getElementById('chat-project-more-btn')?.setAttribute('aria-expanded', 'true');
                }
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

            // Realtime: while a thread panel is open, listen for its events and refresh in place.
            const pusher = new Pusher(@json(config('broadcasting.connections.pusher.key')), {
                wsHost: window.location.hostname,
                wsPort: {{ (int) (config('broadcasting.connections.pusher.options.port') ?: 6001) }},
                forceTLS: false,
                enabledTransports: ['ws', 'wss'],
                cluster: 'mt1',
                channelAuthorization: {
                    endpoint: '/broadcasting/auth',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                },
            });
            let liveChannelName = null;
            const watchThread = (threadId) => {
                if (liveChannelName) pusher.unsubscribe(liveChannelName);
                liveChannelName = 'private-thread.' + threadId;
                const channel = pusher.subscribe(liveChannelName);
                const refresh = async () => {
                    // Staged files cannot survive a re-render — a file input's
                    // value is not settable by script. Skip; the send handler
                    // reloads the panel once the reply goes out.
                    if (document.getElementById('chat-reply-files')?.files.length) return;
                    const nearBottom = ocBody.scrollHeight - ocBody.clientHeight - ocBody.scrollTop < 80;
                    await loadDetail(threadId);
                    if (nearBottom) ocBody.scrollTop = ocBody.scrollHeight;
                };
                channel.bind('message.created', refresh);
                channel.bind('thread.read', refresh);
            };
            document.getElementById('chatOffcanvas').addEventListener('hidden.bs.offcanvas', () => {
                if (liveChannelName) { pusher.unsubscribe(liveChannelName); liveChannelName = null; }
            });

            // Delegated so it keeps working after the live poll replaces the rows.
            document.getElementById('chats-tbody').addEventListener('click', async (e) => {
                const btn = e.target.closest('.js-chat-view');
                if (!btn) return;
                ocBody.innerHTML = '<p class="text-muted">Loading…</p>';
                bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('chatOffcanvas')).show();
                await loadDetail(btn.dataset.threadId);
                ocBody.scrollTop = 0;
                watchThread(btn.dataset.threadId);
            });

            // Live thread sorting: re-fetch and re-render the table body on an
            // interval so newest-activity threads float up without a page
            // refresh. Skipped while searching, while the detail panel is open
            // (avoids yanking the row you're viewing), or past page 1.
            const tbody = document.getElementById('chats-tbody');
            const onFirstPage = !new URLSearchParams(window.location.search).get('page');
            const rowsUrl = () => {
                const p = new URLSearchParams();
                const s = document.getElementById('chats-search').value.trim();
                if (s) p.set('search', s);
                const st = document.querySelector('[name="status"]')?.value;
                if (st) p.set('status', st);
                return '{{ route('chats.rows') }}?' + p.toString();
            };
            let searchFocused = false;
            document.getElementById('chats-search').addEventListener('focus', () => { searchFocused = true; });
            document.getElementById('chats-search').addEventListener('blur', () => { searchFocused = false; });
            async function refreshRows() {
                if (!onFirstPage || searchFocused) return;
                try {
                    const res = await fetch(rowsUrl(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) return;
                    const data = await res.json();
                    tbody.innerHTML = data.rowsHtml;
                } catch (e) { /* keep last render, retry next tick */ }
            }
            // Poll as a fallback; the websocket below is the fast path.
            setInterval(refreshRows, 15000);

            // Live re-sort over soketi: any new message on any thread pings the
            // shared 'threads' channel. Debounced so a burst coalesces into one
            // refresh.
            let rowsDebounce = null;
            const scheduleRefresh = () => {
                clearTimeout(rowsDebounce);
                rowsDebounce = setTimeout(refreshRows, 500);
            };
            pusher.subscribe('private-threads').bind('message.created', scheduleRefresh);

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
                    showAppToast('Thread assigned', 'This thread is now assigned to ' + name + '.', '#28c76f');
                } catch {
                    btn.disabled = false;
                    showAppToast('Assignment failed', 'Could not assign the thread. Try again.', '#ea5455');
                }
            });

            // Reply from the dashboard: delegated — the form arrives with the
            // partial. submit bubbles, so one listener covers every reload.
            ocBody.addEventListener('submit', async (e) => {
                const form = e.target.closest('#chat-reply-form');
                if (!form) return;
                e.preventDefault();

                const text = document.getElementById('chat-reply-text');
                const files = document.getElementById('chat-reply-files');
                const btn = document.getElementById('chat-reply-btn');
                if (!text.value.trim() && !files.files.length) return; // nothing to send

                const body = new FormData();
                body.append('message', text.value);
                for (const file of files.files) body.append('attachments[]', file);

                btn.disabled = true;
                try {
                    const res = await fetch('/chats/' + form.dataset.threadId + '/message', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        body: body,
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.success) throw new Error(data.message || '');
                    // Cleared before the reload so loadDetail's draft-restore
                    // does not put the sent text straight back in the box.
                    text.value = '';
                    await loadDetail(form.dataset.threadId);
                    ocBody.scrollTop = ocBody.scrollHeight;
                    showAppToast('Reply sent', 'Your message was delivered to the client.', '#28c76f');
                } catch (err) {
                    btn.disabled = false;
                    showAppToast('Send failed', err.message || 'Could not send the message. Try again.', '#ea5455');
                }
            });

            // Unblock: delegated — the button lives inside the fetched partial.
            ocBody.addEventListener('click', async (e) => {
                const btn = e.target.closest('#chat-unblock-btn');
                if (!btn) return;

                // Confirm before unblocking. SweetAlert2 if present, native confirm otherwise.
                let confirmed;
                if (typeof Swal !== 'undefined') {
                    const r = await Swal.fire({
                        title: 'Unblock this thread?',
                        text: 'Sending will be enabled again for this thread.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, unblock',
                        cancelButtonText: 'Cancel',
                        customClass: { confirmButton: 'btn btn-danger me-2', cancelButton: 'btn btn-label-secondary' },
                        buttonsStyling: false,
                    });
                    confirmed = r.isConfirmed;
                } else {
                    confirmed = window.confirm('Unblock this thread? Sending will be enabled again.');
                }
                if (!confirmed) return;

                btn.disabled = true;
                try {
                    const res = await fetch('/chats/' + btn.dataset.threadId + '/unblock', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!res.ok) throw new Error();
                    await loadDetail(btn.dataset.threadId);
                    showAppToast('Thread unblocked', 'Sending is enabled again for this thread.', '#28c76f');
                } catch {
                    btn.disabled = false;
                    showAppToast('Unblock failed', 'Could not unblock the thread. Try again.', '#ea5455');
                }
            });
        });
    </script>
@endsection
