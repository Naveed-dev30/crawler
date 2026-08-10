@php
    $checkTab = $checkTab ?? 'remaining';
    $idColor = $checkTab === 'Correct' ? 'text-success' : ($checkTab === 'Incorrect' ? 'text-danger' : '');
@endphp
<tr>
    <td>
        <span class="fw-semibold {{ $idColor }}">{{ $proposal->project_id }}</span>
        <div class="d-flex gap-1 mt-2">
            @if ($checkTab !== 'Correct')
                <button type="button"
                        class="btn rounded-pill d-inline-flex align-items-center nq-check-btn {{ $proposal->qualify_check === 'Correct' ? 'btn-success' : 'btn-outline-success' }}"
                        style="--bs-btn-padding-y: .1rem; --bs-btn-padding-x: .6rem; --bs-btn-font-size: .75rem;"
                        data-proposal-id="{{ $proposal->id }}" data-check="Correct">
                    <i class="bx bx-check me-1"></i>Correct
                </button>
            @endif
            @if ($checkTab !== 'Incorrect')
                <button type="button"
                        class="btn rounded-pill d-inline-flex align-items-center nq-check-btn {{ $proposal->qualify_check === 'Incorrect' ? 'btn-danger' : 'btn-outline-danger' }}"
                        style="--bs-btn-padding-y: .1rem; --bs-btn-padding-x: .6rem; --bs-btn-font-size: .75rem;"
                        data-proposal-id="{{ $proposal->id }}" data-check="Incorrect">
                    <i class="bx bx-x me-1"></i>Incorrect
                </button>
            @endif
        </div>
    </td>
    <td>{{ \Illuminate\Support\Str::limit($proposal->title, 40) }}</td>
    <td class="nq-wrap">
        <span class="fw-bold nq-clamp">{{ $proposal->qualify_reason }}</span>
        @if (mb_strlen((string) $proposal->qualify_reason) > 80)
            <a href="#" class="nq-more small">More</a>
        @endif
    </td>
    <td class="nq-wrap">
        @if (trim((string) $proposal->qualify_summary) !== '')
            <span class="fw-light nq-clamp">{{ $proposal->qualify_summary }}</span>
            @if (mb_strlen((string) $proposal->qualify_summary) > 80)
                <a href="#" class="nq-more small">More</a>
            @endif
        @else
            <span class="text-muted fst-italic">No summary available</span>
        @endif
    </td>
    <td>
        {{ $proposal->created_at->diffForHumans(null, true) }}
        <br>
        <small class="text-muted">
            @if ($proposal->min_budget !== null || $proposal->max_budget !== null)
                ${{ $proposal->min_budget + 0 }}–${{ $proposal->max_budget + 0 }}
            @endif
            @if ($proposal->type)
                · {{ $proposal->type }}
            @endif
        </small>
    </td>
    <td>
        <button type="button" class="btn btn-sm btn-label-primary js-nq-view" data-proposal-id="{{ $proposal->id }}">
            <i class="fa fa-eye me-1"></i> View
        </button>
    </td>
</tr>
