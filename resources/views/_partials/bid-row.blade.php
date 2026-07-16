@php
    $statusClass = $bid->bid_status === 'completed'
        ? 'bg-label-success'
        : ($bid->bid_status === 'pending' ? 'bg-label-warning' : 'bg-label-danger');
    $checkIcon = $bid->check === 'Correct'
        ? 'fa fa-check text-success'
        : ($bid->check === 'Incorrect' ? 'fa fa-close text-danger' : 'fa fa-warning text-warning');
    $checkLabel = $bid->check === 'Correct'
        ? 'Marked Correct'
        : ($bid->check === 'Incorrect' ? 'Marked Incorrect' : 'Unreviewed');
@endphp
<tr>
    <td>{{ $bid->proposal->project_id }}</td>
    <td>{{ \Illuminate\Support\Str::limit($bid->proposal->title, 30) }}</td>
    <td>{{ $bid->price }}$ - {{ $bid->proposal->country }}</td>
    <td>
        <span class="badge {{ $statusClass }} me-1">{{ $bid->bid_status }}</span>
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
                <span class="badge" style="color:#399cff;background-color:rgba(57,156,255,.12)">True</span>
            @else
                <span class="badge bg-label-secondary">False</span>
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
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-label-primary bid-view-btn" data-bid-id="{{ $bid->id }}">
                <i class="fa fa-eye me-1"></i> View
            </button>
            <i class="{{ $checkIcon }}" data-check-dot="{{ $bid->id }}" style="cursor: help;"
               data-bs-toggle="tooltip" data-bs-custom-class="tooltip-light" title="{{ $checkLabel }}"></i>
        </div>
    </td>
</tr>
