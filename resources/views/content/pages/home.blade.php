@extends('layouts.layoutMaster')

@section('title', 'Bids')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h4 class="page-title mb-0">Bids</h4>
        <form action="{{ route('expire_bids') }}" method="POST" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-danger">Expire Pending</button>
        </form>
    </div>

    {{-- Filter bar (sticky on scroll) --}}
    <div class="card mb-3" style="position: sticky; top: 0.75rem; z-index: 1020;">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2 bid-only-filter">
                    <label class="form-label mb-1">From</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2 bid-only-filter">
                    <label class="form-label mb-1">To</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2 bid-only-filter">
                    <label class="form-label mb-1">Min amount</label>
                    <input type="number" id="f-min" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-6 col-md-2 bid-only-filter">
                    <label class="form-label mb-1">Max amount</label>
                    <input type="number" id="f-max" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-6 col-md-2 bid-only-filter">
                    <label class="form-label mb-1">Type</label>
                    <select id="f-type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="hourly">Hourly</option>
                        <option value="fixed">Fixed</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1">Search</label>
                    <input type="text" id="f-search" class="form-control form-control-sm" placeholder="Title or project id">
                </div>
            </div>
        </div>
    </div>

    {{-- Cards --}}
    <div class="row g-3 mb-3" id="bids-cards">
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Total</span>
                        <h2 class="mb-0 fw-bold" id="card-total">—</h2>
                    </div>
                    <span class="badge bg-label-secondary rounded p-2 lh-1"><i class="bx bx-layer bx-sm"></i></span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Placed</span>
                        <h2 class="mb-1 fw-bold" id="card-placed" style="color:#696cff">—</h2>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-label-success d-inline-flex align-items-center gap-1 fw-semibold">Completed<span class="badge bg-white text-dark ms-1" id="sub-completed">—</span></span>
                            <span class="badge bg-label-warning d-inline-flex align-items-center gap-1 fw-semibold">Pending<span class="badge bg-white text-dark ms-1" id="sub-pending">—</span></span>
                        </div>
                    </div>
                    <span class="badge rounded p-2 lh-1" style="color:#696cff;background:rgba(105,108,255,.12)"><i
                            class="bx bx-check-circle bx-sm"></i></span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold">Failed</span>
                        <h2 class="mb-1 fw-bold text-danger" id="card-failed">—</h2>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-label-danger d-inline-flex align-items-center gap-1 fw-semibold">Failed<span class="badge bg-white text-dark ms-1" id="sub-failed">—</span></span>
                            <span class="badge bg-label-secondary d-inline-flex align-items-center gap-1 fw-semibold">Expired<span class="badge bg-white text-dark ms-1" id="sub-expired">—</span></span>
                        </div>
                    </div>
                    <span class="badge bg-label-danger rounded p-2 lh-1"><i class="bx bx-x-circle bx-sm"></i></span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabs + table --}}
    <style>
        #bids-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
            border: 0;
            border-bottom: 3px solid transparent;
            border-radius: 0;
            padding: .5rem 1.25rem;
        }

        #bids-tabs .nav-link:hover {
            color: #5a5f66;
        }

        #bids-tabs .nav-link.active {
            color: #696cff;
            font-weight: 700;
            background-color: rgba(105, 108, 255, .12);
            border-bottom-color: #696cff;
            border-radius: .375rem .375rem 0 0;
        }

        #bids-tabs .nav-link[data-tab="failed"].active {
            color: #ff3e1d;
            background-color: rgba(255, 62, 29, .12);
            border-bottom-color: #ff3e1d;
        }

        #bids-tabs .nav-link[data-tab="completed"].active {
            color: #28c76f;
            background-color: rgba(40, 199, 111, .12);
            border-bottom-color: #28c76f;
        }

        #bids-tabs .nav-link[data-tab="not-qualified"].active {
            color: #00cfe8;
            background-color: rgba(0, 207, 232, .12);
            border-bottom-color: #00cfe8;
        }

        #bids-tabs .nav-link[data-tab="skill-not-matched"].active {
            color: #ffab00;
            background-color: rgba(255, 171, 0, .12);
            border-bottom-color: #ffab00;
        }

        .bids-table thead th {
            text-transform: uppercase;
            font-size: .72rem;
            letter-spacing: .5px;
            color: #a1acb8;
        }

        /* Not Qualified tab: long reason/summary text wraps instead of stretching the table,
           clamped to 3 lines with a More/Less toggle */
        .bids-table td.nq-wrap {
            white-space: normal;
            word-break: break-word;
            max-width: 26rem;
        }

        .bids-table td.nq-wrap .nq-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .bids-table td.nq-wrap .nq-clamp.expanded {
            display: inline;
            -webkit-line-clamp: unset;
        }

        .bids-table td {
            padding-top: .6rem;
            padding-bottom: .6rem;
        }

        .bids-table tbody tr {
            transition: background-color .12s ease;
        }

        .bids-table tbody tr:hover {
            background-color: rgba(105, 108, 255, .05);
        }

        .tooltip-light .tooltip-inner {
            background-color: #fff;
            color: #384551;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .15);
            font-weight: 500;
        }

        .tooltip-light.bs-tooltip-top .tooltip-arrow::before {
            border-top-color: #fff;
        }

        .tooltip-light.bs-tooltip-bottom .tooltip-arrow::before {
            border-bottom-color: #fff;
        }

        .tooltip-light.bs-tooltip-start .tooltip-arrow::before {
            border-left-color: #fff;
        }

        .tooltip-light.bs-tooltip-end .tooltip-arrow::before {
            border-right-color: #fff;
        }
    </style>
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="bids-tabs">
                <li class="nav-item"><button class="nav-link active" data-tab="completed" type="button">Bids Placed</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="failed" type="button">Failed</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="skill-not-matched" type="button">Skills Not Matched</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="not-qualified" type="button">Not Qualified</button></li>
            </ul>
        </div>
        {{-- Bids Placed only: review-state sub-tabs --}}
        <div class="px-3 py-2 border-bottom" id="bids-check-tabs">
            <ul class="nav nav-pills gap-1 mb-0">
                <li class="nav-item"><button type="button" class="nav-link active py-1 px-3" data-check-tab="all">All</button></li>
                <li class="nav-item"><button type="button" class="nav-link py-1 px-3" data-check-tab="Correct">Correct</button></li>
                <li class="nav-item"><button type="button" class="nav-link py-1 px-3" data-check-tab="Incorrect">Incorrect</button></li>
            </ul>
        </div>
        {{-- Skills Not Matched only: interest sub-tabs --}}
        <div class="px-3 py-2 border-bottom d-none" id="bids-interest-tabs">
            <ul class="nav nav-pills gap-1 mb-0">
                <li class="nav-item"><button type="button" class="nav-link active py-1 px-3" data-interest-tab="all">All</button></li>
                <li class="nav-item"><button type="button" class="nav-link py-1 px-3" data-interest-tab="Interested">Interested</button></li>
                <li class="nav-item"><button type="button" class="nav-link py-1 px-3" data-interest-tab="Not Interested">Not Interested</button></li>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle bids-table mb-0">
                <thead>
                    <tr id="thead-bids">
                        <th>ID</th>
                        <th>Title</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th class="completed-col d-none text-nowrap">Awarded</th>
                        <th class="completed-col d-none text-nowrap">Awarded Price</th>
                        <th>Time</th>
                        <th>Review</th>
                    </tr>
                    <tr id="thead-nq" class="d-none">
                        <th>Project</th>
                        <th>Title</th>
                        <th>Reason</th>
                        <th>Summary</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="bids-tbody">
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Loading…</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4 card px-4 pt-3" id="bids-pagination"></div>

    {{-- Reused left slide-over: no backdrop so other rows stay clickable; scrollable body --}}
    <div class="offcanvas offcanvas-start" tabindex="-1" id="bidOffcanvas" data-bs-backdrop="false" data-bs-scroll="true"
        style="width: 52rem; max-width: 95vw;">
        <div id="bidOffcanvasContent" class="h-100 d-flex flex-column"></div>
    </div>
@endsection

@section('page-script')
    @include('_partials.toast-helper')
    @if (session('status'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                window.showAppToast('Done', @json(session('status')), '#28c76f');
            });
        </script>
    @endif
    <script>
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            let currentTab = 'completed';
            let currentCheck = 'all';
            let currentInterest = 'all';
            let currentPage = 1;
            let searchFocused = false;

            const el = id => document.getElementById(id);

            function buildParams() {
                const p = new URLSearchParams();
                p.set('tab', currentTab);
                p.set('page', currentPage);
                if (currentTab === 'completed' && currentCheck !== 'all') p.set('check', currentCheck);
                if (currentTab === 'skill-not-matched' && currentInterest !== 'all') p.set('interest', currentInterest);
                const from = el('f-from').value; if (from) p.set('from', from);
                const to = el('f-to').value; if (to) p.set('to', to);
                const min = el('f-min').value; if (min) p.set('min', min);
                const max = el('f-max').value; if (max) p.set('max', max);
                const type = el('f-type').value; if (type) p.set('type', type);
                const q = el('f-search').value.trim(); if (q) p.set('q', q);
                return p;
            }

            async function loadData() {
                let data;
                try {
                    const res = await fetch('/bids/data?' + buildParams().toString(), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) return;           // keep last render, retry next tick
                    data = await res.json();
                } catch (e) { return; }

                el('card-total').textContent = data.cards.total;
                el('card-placed').textContent = data.cards.placed;
                el('card-failed').textContent = data.cards.failed;
                const sc = {};
                Object.entries(data.statusCounts || {}).forEach(([s, c]) => { sc[String(s).toLowerCase()] = c; });
                el('sub-completed').textContent = sc.completed || 0;
                el('sub-pending').textContent = sc.pending || 0;
                el('sub-failed').textContent = sc.failed || 0;
                el('sub-expired').textContent = sc.expired || 0;
                document.querySelectorAll('.completed-col').forEach(th =>
                    th.classList.toggle('d-none', currentTab !== 'completed'));
                el('bids-check-tabs').classList.toggle('d-none', currentTab !== 'completed');
                el('bids-interest-tabs').classList.toggle('d-none', currentTab !== 'skill-not-matched');
                const nq = currentTab === 'not-qualified';
                el('thead-bids').classList.toggle('d-none', nq);
                el('thead-nq').classList.toggle('d-none', !nq);
                document.querySelectorAll('.bid-only-filter').forEach(d => d.classList.toggle('d-none', nq));
                el('bids-cards').classList.toggle('d-none', nq);
                // Auto-refresh replaces the rows — remember which More/Less blocks
                // the user expanded (keyed by proposal id + cell) and restore them.
                const expandedKeys = [...el('bids-tbody').querySelectorAll('.nq-clamp.expanded')].map(span => {
                    const tr = span.closest('tr');
                    const id = tr?.querySelector('.js-nq-view')?.dataset.proposalId;
                    return id ? id + ':' + [...tr.children].indexOf(span.closest('td')) : null;
                }).filter(Boolean);
                el('bids-tbody').innerHTML = data.rowsHtml;
                expandedKeys.forEach(key => {
                    const [id, tdIndex] = key.split(':');
                    const tr = el('bids-tbody').querySelector('.js-nq-view[data-proposal-id="' + id + '"]')?.closest('tr');
                    const td = tr?.children[tdIndex];
                    const span = td?.querySelector('.nq-clamp');
                    const link = td?.querySelector('.nq-more');
                    if (span) { span.classList.add('expanded'); }
                    if (link) { link.textContent = 'Less'; }
                });
                el('bids-pagination').innerHTML = data.paginationHtml;
                el('bids-pagination').style.display = data.paginationHtml.trim() ? '' : 'none';
                initTooltips();
            }

            function initTooltips() {
                el('bids-tbody').querySelectorAll('[data-bs-toggle="tooltip"]').forEach(node => {
                    bootstrap.Tooltip.getOrCreateInstance(node);
                });
            }

            function reload() { currentPage = 1; loadData(); }

            // Filters: dates + type apply on change; amounts + search apply as you type (debounced)
            ['f-from', 'f-to', 'f-type'].forEach(id => el(id).addEventListener('change', reload));
            let filterTimer;
            const debouncedReload = () => { clearTimeout(filterTimer); filterTimer = setTimeout(reload, 400); };
            ['f-min', 'f-max', 'f-search'].forEach(id => el(id).addEventListener('input', debouncedReload));
            el('f-search').addEventListener('focus', () => searchFocused = true);
            el('f-search').addEventListener('blur', () => searchFocused = false);

            // Tabs
            document.querySelectorAll('#bids-tabs .nav-link').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('#bids-tabs .nav-link').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentTab = this.dataset.tab;
                    // Switching main tabs resets both sub-tab groups
                    currentCheck = 'all';
                    currentInterest = 'all';
                    document.querySelectorAll('#bids-check-tabs .nav-link').forEach(b =>
                        b.classList.toggle('active', b.dataset.checkTab === 'all'));
                    document.querySelectorAll('#bids-interest-tabs .nav-link').forEach(b =>
                        b.classList.toggle('active', b.dataset.interestTab === 'all'));
                    reload();
                });
            });

            // Review sub-tabs (Bids Placed only)
            document.querySelectorAll('#bids-check-tabs .nav-link').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('#bids-check-tabs .nav-link').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentCheck = this.dataset.checkTab;
                    reload();
                });
            });

            // Interest sub-tabs (Skills Not Matched only)
            document.querySelectorAll('#bids-interest-tabs .nav-link').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('#bids-interest-tabs .nav-link').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentInterest = this.dataset.interestTab;
                    reload();
                });
            });

            // Delegated: Interested/Not Interested buttons on table rows
            el('bids-tbody').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-interest-btn');
                if (!btn) return;
                const interest = btn.dataset.interest;
                const res = await fetch('/updateBidInterest', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ bid_id: btn.dataset.bidId, interest: interest })
                });
                if (!res.ok) {
                    window.showAppToast('Failed', 'Could not save — try again.', '#ff3e1d');
                    return;
                }
                window.showAppToast(
                    interest === 'Interested' ? 'Marked Interested' : 'Marked Not Interested',
                    'Saved.',
                    interest === 'Interested' ? '#28c76f' : '#ff3e1d'
                );
                loadData();
            });

            // Delegated: Correct/Incorrect buttons on table rows
            el('bids-tbody').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-check-btn');
                if (!btn) return;
                const check = btn.dataset.check;
                const res = await fetch('/updateBidCheck', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ bid_id: btn.dataset.bidId, check: check })
                });
                if (!res.ok) {
                    window.showAppToast('Failed', 'Could not save review — try again.', '#ff3e1d');
                    return;
                }
                window.showAppToast(
                    check === 'Correct' ? 'Marked Correct' : 'Marked Incorrect',
                    'Bid review saved.',
                    check === 'Correct' ? '#28c76f' : '#ff3e1d'
                );
                // If the slide-over is open on this bid, re-fetch it so badge + buttons match
                const panelBody = el('bidOffcanvasContent').querySelector('[data-bid-id="' + btn.dataset.bidId + '"]');
                if (panelBody && el('bidOffcanvas').classList.contains('show')) {
                    const detail = await fetch('/bids/' + btn.dataset.bidId + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (detail.ok) {
                        el('bidOffcanvasContent').innerHTML = await detail.text();
                    }
                }
                loadData();
            });

            // Delegated: pagination links
            el('bids-pagination').addEventListener('click', function (ev) {
                const a = ev.target.closest('a');
                if (!a) return;
                ev.preventDefault();
                const url = new URL(a.href, window.location.origin);
                const page = url.searchParams.get('page');
                if (page) { currentPage = parseInt(page, 10); loadData(); }
            });

            // Delegated: More/Less toggle for clamped not-qualified text
            el('bids-tbody').addEventListener('click', function (ev) {
                const link = ev.target.closest('.nq-more');
                if (!link) return;
                ev.preventDefault();
                const span = link.parentElement.querySelector('.nq-clamp');
                if (!span) return;
                const expanded = span.classList.toggle('expanded');
                link.textContent = expanded ? 'Less' : 'More';
            });

            // Delegated: open not-qualified proposal slide-over
            el('bids-tbody').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.js-nq-view');
                if (!btn) return;
                const res = await fetch('/proposals/' + btn.dataset.proposalId + '/nq-detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return;
                el('bidOffcanvasContent').innerHTML = await res.text();
                bootstrap.Offcanvas.getOrCreateInstance(el('bidOffcanvas')).show();
            });

            // Delegated: open slide-over (swap content if already open)
            el('bids-tbody').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-view-btn');
                if (!btn) return;
                const id = btn.dataset.bidId;
                const res = await fetch('/bids/' + id + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return;
                el('bidOffcanvasContent').innerHTML = await res.text();
                bootstrap.Offcanvas.getOrCreateInstance(el('bidOffcanvas')).show();
            });

            // Delegated: Correct/Incorrect inside the panel
            el('bidOffcanvasContent').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-check-btn');
                if (!btn) return;
                const id = btn.dataset.bidId;
                const check = btn.dataset.check;
                const res = await fetch('/updateBidCheck', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ bid_id: id, check: check })
                });
                if (!res.ok) {
                    window.showAppToast('Failed', 'Could not save review — try again.', '#ff3e1d');
                    return;
                }
                window.showAppToast(
                    check === 'Correct' ? 'Marked Correct' : 'Marked Incorrect',
                    'Bid review saved.',
                    check === 'Correct' ? '#28c76f' : '#ff3e1d'
                );
                // Re-fetch the panel so badge and buttons reflect the new state,
                // and reload the table so the row buttons flip too.
                const detail = await fetch('/bids/' + id + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (detail.ok) {
                    el('bidOffcanvasContent').innerHTML = await detail.text();
                }
                loadData();
            });

            // Auto-refresh: skip while typing search or past page 1
            setInterval(() => { if (!searchFocused && currentPage === 1) loadData(); }, 15000);

            // Initial load
            loadData();
        })();
    </script>
@endsection