@extends('layouts.layoutMaster')

@section('title', 'Upwork Opportunities')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h4 class="page-title mb-0 d-flex align-items-center gap-2">
            <i class="bx bxl-upwork" style="color:#14a800;"></i> Upwork Opportunities
        </h4>
    </div>

    <style>
        .upwork-table thead th {
            text-transform: uppercase;
            font-size: .72rem;
            letter-spacing: .5px;
            color: #a1acb8;
        }

        .upwork-table td {
            padding-top: .6rem;
            padding-bottom: .6rem;
        }

        .upwork-table tbody tr:hover {
            background-color: rgba(20, 168, 0, .05);
        }
    </style>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle upwork-table mb-0">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Budget / Rate</th>
                        <th>Posted</th>
                        <th>Skills</th>
                        <th>Client</th>
                    </tr>
                </thead>
                <tbody id="upwork-tbody">
                    <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4 card px-4 pt-3" id="upwork-pagination"></div>
@endsection

@section('page-script')
    <script>
        (function () {
            const el = id => document.getElementById(id);
            let page = 1;

            async function load() {
                try {
                    const res = await fetch('/bids/upwork/data?page=' + page, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    el('upwork-tbody').innerHTML = data.rowsHtml;
                    el('upwork-pagination').innerHTML = data.paginationHtml;
                    el('upwork-pagination').style.display = data.paginationHtml.trim() ? '' : 'none';
                } catch (e) { /* keep last render */ }
            }

            el('upwork-pagination').addEventListener('click', function (ev) {
                const a = ev.target.closest('a');
                if (!a) return;
                ev.preventDefault();
                const p = new URL(a.href, window.location.origin).searchParams.get('page');
                if (p) { page = parseInt(p, 10); load(); }
            });

            load();
        })();
    </script>
@endsection
