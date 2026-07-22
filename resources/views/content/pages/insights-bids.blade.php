@extends('layouts/layoutMaster')

@section('title', 'Bid Insights')

@section('content')
    <h4 class="page-title">Bid Insights</h4>

    @if ($bids->isEmpty())
        <div class="card"><div class="card-body">
            <p class="text-muted mb-0 py-4 text-center">No bid insights yet</p>
        </div></div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Time to Bid</th>
                            <th>Submitted</th>
                            <th>Bid Amount</th>
                            <th>Client</th>
                            <th>Bid Rank</th>
                            <th>Winning Bid</th>
                            <th>Actions</th>
                            <th>Last Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bids as $bid)
                            <tr>
                                <td>
                                    @if ($bid->project_url)
                                        <a href="{{ $bid->project_url }}" target="_blank" rel="noopener">{{ $bid->project_id }}</a>
                                    @else
                                        {{ $bid->project_id }}
                                    @endif
                                </td>
                                <td>
                                    @if ($bid->time_to_bid_seconds !== null)
                                        {{ $bid->time_to_bid_seconds < 60
                                            ? $bid->time_to_bid_seconds . 's'
                                            : intdiv($bid->time_to_bid_seconds, 60) . 'm ' . ($bid->time_to_bid_seconds % 60) . 's' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $bid->time_submitted?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>{{ $bid->bid_amount !== null ? number_format($bid->bid_amount, 2) . ' ' . ($bid->bid_currency ?? '') : '—' }}</td>
                                <td>
                                    {{ $bid->client_country ?? '—' }}
                                    @if ($bid->client_rating !== null)
                                        · {{ number_format($bid->client_rating, 1) }}★
                                    @endif
                                    @if ($bid->client_reviews !== null)
                                        · {{ $bid->client_reviews }} reviews
                                    @endif
                                </td>
                                <td>{{ $bid->bid_rank !== null ? '#' . $bid->bid_rank : '—' }}</td>
                                <td>
                                    @if ($bid->winning_bid_sealed)
                                        Sealed
                                    @elseif ($bid->winning_bid_amount !== null)
                                        {{ number_format($bid->winning_bid_amount, 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ count($bid->actions_taken ?? []) }}</td>
                                <td>{{ $bid->last_scraped_at?->format('Y-m-d H:i') }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary js-changes"
                                            data-bid-id="{{ $bid->id }}" data-project-id="{{ $bid->project_id }}"
                                            data-bs-toggle="modal" data-bs-target="#changesModal">
                                        Changes
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($bids->hasPages())
            <div class="mt-4 card px-4 pt-3">
                {{ $bids->links('vendor.pagination.bootstrap-5') }}
            </div>
        @endif

        {{-- Audit log modal --}}
        <div class="modal fade" id="changesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Change History — Project <span id="changesProjectId"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="changesBody">
                        <p class="text-muted mb-0">Loading…</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@section('page-script')
    <script>
        (function () {
            const body = document.getElementById('changesBody');
            const projectSpan = document.getElementById('changesProjectId');
            if (! body) { return; }

            document.querySelectorAll('.js-changes').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    projectSpan.textContent = btn.dataset.projectId;
                    body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';

                    fetch('/api/insights/bids/' + btn.dataset.bidId + '/changes')
                        .then(function (res) {
                            if (! res.ok) { throw new Error('HTTP ' + res.status); }
                            return res.json();
                        })
                        .then(function (json) {
                            const rows = json.data || [];
                            if (! rows.length) {
                                body.innerHTML = '<p class="text-muted mb-0">No changes recorded</p>';
                                return;
                            }
                            let html = '<table class="table table-sm"><thead><tr>' +
                                '<th>Field</th><th>Old</th><th>New</th><th>Observed At</th></tr></thead><tbody>';
                            rows.forEach(function (c) {
                                html += '<tr><td>' + esc(c.field) + '</td><td>' + esc(c.old_value) +
                                    '</td><td>' + esc(c.new_value) + '</td><td>' + esc(c.observed_at) + '</td></tr>';
                            });
                            body.innerHTML = html + '</tbody></table>';
                        })
                        .catch(function () {
                            body.innerHTML = '<p class="text-danger mb-0">Failed to load changes</p>';
                        });
                });
            });

            function esc(v) {
                if (v === null || v === undefined) { return '—'; }
                const d = document.createElement('div');
                d.textContent = String(v);
                return d.innerHTML;
            }
        })();
    </script>
@endsection
