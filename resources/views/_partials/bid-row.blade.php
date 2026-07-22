@php
    $statusClass = $bid->bid_status === 'completed'
        ? 'bg-label-success'
        : ($bid->bid_status === 'pending' ? 'bg-label-warning' : 'bg-label-danger');
@endphp
<tr>
    <td>
        {{ $bid->proposal->project_id }}
        @if (!empty($completed))
            @php $checkTab = $checkTab ?? 'all'; @endphp
            <div class="d-flex gap-1 mt-1">
                @if ($checkTab !== 'Correct')
                    <button type="button"
                            class="btn btn-sm py-0 px-2 bid-check-btn {{ $bid->check === 'Correct' ? 'btn-success' : 'btn-outline-success' }}"
                            data-bid-id="{{ $bid->id }}" data-check="Correct">Correct</button>
                @endif
                @if ($checkTab !== 'Incorrect')
                    <button type="button"
                            class="btn btn-sm py-0 px-2 bid-check-btn {{ $bid->check === 'Incorrect' ? 'btn-danger' : 'btn-outline-danger' }}"
                            data-bid-id="{{ $bid->id }}" data-check="Incorrect">Incorrect</button>
                @endif
            </div>
        @endif
    </td>
    <td>{{ \Illuminate\Support\Str::limit($bid->proposal->title, 30) }}</td>
    <td>{{ $bid->price }}$ - {{ $bid->proposal->country }}</td>
    @php
        $isFailure = in_array(strtolower($bid->bid_status), ['failed', 'expired'], true);
        $skillFail = $isFailure && str_contains(strtolower((string) $bid->error_message), 'skill');
    @endphp
    <td>
        @if ($skillFail)
            <span class="badge bg-label-warning me-1"><i class="fa fa-wrench me-1"></i>Skills Not Matched</span>
        @elseif ($isFailure)
            <span class="badge bg-label-danger me-1">failed</span>
        @else
            <span class="badge {{ $statusClass }} me-1">{{ $bid->bid_status === 'completed' ? 'Bid Placed' : $bid->bid_status }}</span>
        @endif
        @if (empty($completed) && $bid->bid_status === 'completed')
            <div class="mt-1 small">
                @if ($bid->awarded)
                    <span class="fw-semibold" style="color:#399cff">
                        <i class="fa fa-trophy me-1"></i>Awarded{{ $bid->awarded_price !== null ? ' · ' . $bid->awarded_price . '$' : '' }}
                    </span>
                @else
                    <span class="text-muted">
                        <i class="fa fa-minus-circle me-1"></i>Not awarded
                    </span>
                @endif
            </div>
        @endif
    </td>
    <td>{{ $bid->proposal->type }}</td>
    @if (!empty($completed))
        <td>
            @if ($bid->awarded)
                <span class="badge" style="color:#399cff;background-color:rgba(57,156,255,.12)">Yes</span>
            @else
                <span class="badge bg-label-secondary">No</span>
            @endif
        </td>
        <td>{{ $bid->awarded_price !== null ? $bid->awarded_price . '$' : '—' }}</td>
    @endif
    <td>
        <div class="col">
            <div class="row">{{ $bid->created_at->copy()->timezone('Asia/Karachi')->format('h:i a') }}</div>
            <div class="row text-light">{{ $bid->created_at->diffForHumans(null, true) }}</div>
        </div>
    </td>
    <td>
        <button type="button" class="btn btn-sm btn-label-primary bid-view-btn" data-bid-id="{{ $bid->id }}">
            <i class="fa fa-eye me-1"></i> View
        </button>
    </td>
</tr>
