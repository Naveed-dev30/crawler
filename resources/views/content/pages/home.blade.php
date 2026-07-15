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

    {{-- Filter bar --}}
    <div class="card mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">From</label>
                <input type="date" id="f-from" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">To</label>
                <input type="date" id="f-to" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">Min amount</label>
                <input type="number" id="f-min" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">Max amount</label>
                <input type="number" id="f-max" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-6 col-md-2">
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
    </div></div>

    {{-- Cards --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card"><div class="card-body">
            <span class="text-muted">Total</span><h3 id="card-total">—</h3>
        </div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">
            <span class="text-muted">Placed</span><h3 id="card-placed" class="text-primary">—</h3>
        </div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">
            <span class="text-muted">Failed</span><h3 id="card-failed" class="text-danger">—</h3>
        </div></div></div>
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
        #bids-tabs .nav-link:hover { color: #5a5f66; }
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
    </style>
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="bids-tabs">
                <li class="nav-item"><button class="nav-link active" data-tab="placed" type="button">Placed Bids</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="failed" type="button">Failed Bids</button></li>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>ID</th><th>Title</th><th>Price</th><th>Status</th><th>Type</th><th>Time</th><th>Review</th>
                    </tr>
                </thead>
                <tbody id="bids-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4 card px-4 pt-3" id="bids-pagination"></div>

    {{-- Reused left slide-over: no backdrop so other rows stay clickable; scrollable body --}}
    <div class="offcanvas offcanvas-start" tabindex="-1" id="bidOffcanvas"
         data-bs-backdrop="false" data-bs-scroll="true"
         style="width: 44rem; max-width: 95vw;">
        <div id="bidOffcanvasContent" class="h-100 d-flex flex-column"></div>
    </div>
@endsection

@section('page-script')
    <script>
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            let currentTab = 'placed';
            let currentPage = 1;
            let searchFocused = false;

            const el = id => document.getElementById(id);

            function buildParams() {
                const p = new URLSearchParams();
                p.set('tab', currentTab);
                p.set('page', currentPage);
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
                el('bids-tbody').innerHTML = data.rowsHtml;
                el('bids-pagination').innerHTML = data.paginationHtml;
                el('bids-pagination').style.display = data.paginationHtml.trim() ? '' : 'none';
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
                    reload();
                });
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

            // Delegated: open slide-over (swap content if already open)
            el('bids-tbody').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-view-btn');
                if (!btn) return;
                const id = btn.dataset.bidId;
                const res = await fetch('/bids/' + id + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return;
                el('bidOffcanvasContent').innerHTML = await res.text();
                // mark the row's eye as seen
                const icon = btn.querySelector('i');
                if (icon) { icon.classList.add('text-success'); }
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
                if (!res.ok) return;
                const badge = el('bidOffcanvasContent').querySelector('[data-check-badge]');
                if (badge) {
                    badge.textContent = check;
                    badge.className = 'badge ' + (check === 'Correct' ? 'bg-success' : 'bg-danger');
                }
                const dot = document.querySelector('[data-check-dot="' + id + '"]');
                if (dot) {
                    dot.className = (check === 'Correct' ? 'fa fa-check text-success' : 'fa fa-close text-danger') + ' ms-1';
                    dot.setAttribute('data-check-dot', id);
                }
            });

            // Auto-refresh: skip while typing search or past page 1
            setInterval(() => { if (!searchFocused && currentPage === 1) loadData(); }, 15000);

            // Initial load
            loadData();
        })();
    </script>
@endsection
