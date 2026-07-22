@php
    $checkBadge = $bid->check === 'Correct'
        ? 'bg-success'
        : ($bid->check === 'Incorrect' ? 'bg-danger' : 'bg-warning');
@endphp
<div class="offcanvas-header">
    <h5 class="offcanvas-title">Bid #{{ $bid->id }}</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body" data-bid-id="{{ $bid->id }}">
    <div class="d-flex justify-content-between align-items-center gap-2">
        <span class="badge {{ $checkBadge }}" data-check-badge>{{ $bid->check }}</span>
        <div class="d-flex gap-1">
            @if ($bid->check !== 'Correct')
                <button type="button"
                        class="btn rounded-pill d-inline-flex align-items-center btn-outline-success bid-check-btn"
                        style="--bs-btn-padding-y: .1rem; --bs-btn-padding-x: .6rem; --bs-btn-font-size: .75rem;"
                        data-bid-id="{{ $bid->id }}" data-check="Correct">
                    <i class="bx bx-check me-1"></i>Correct
                </button>
            @endif
            @if ($bid->check !== 'Incorrect')
                <button type="button"
                        class="btn rounded-pill d-inline-flex align-items-center btn-outline-danger bid-check-btn"
                        style="--bs-btn-padding-y: .1rem; --bs-btn-padding-x: .6rem; --bs-btn-font-size: .75rem;"
                        data-bid-id="{{ $bid->id }}" data-check="Incorrect">
                    <i class="bx bx-x me-1"></i>Incorrect
                </button>
            @endif
        </div>
    </div>

    <p class="mt-3 mb-1">Last Updated: {{ $bid->proposal->updated_at->copy()->timezone('Asia/Karachi')->format('d-M, Y h:i a') }}</p>
    <h6>Project Min Budget: <span class="fw-light">{{ $bid->proposal->min_budget }}$</span></h6>
    <h6>Project Max Budget: <span class="fw-light">{{ $bid->proposal->max_budget }}$</span></h6>
    <h6>Project Quoted: <span class="fw-light">{{ $bid->price }}</span></h6>
    <h6>Bid Status: <span class="fw-light">{{ $bid->bid_status }}</span></h6>
    @if (strtolower($bid->bid_status) === 'failed')
        <span class="fw-light text-danger">{{ $bid->error_message }}</span>
    @endif
    <h6>Type: <span class="fw-light">{{ $bid->proposal->type }}</span></h6>

    <div class="divider divider-primary"><div class="divider-text">Title/Description</div></div>
    <h6>Title:</h6>
    <span class="fw-light">{{ $bid->proposal->title }}</span>
    <h6 class="mt-4">Project Description:</h6>
    <span class="fw-light">{{ $bid->proposal->description }}</span>

    @if (trim((string) $bid->proposal->qualify_reason) !== '')
        <div class="divider divider-primary"><div class="divider-text">Qualification</div></div>
        <h6>Reason:</h6>
        <span class="fw-bold">{{ $bid->proposal->qualify_reason }}</span>
        <h6 class="mt-3">Summary:</h6>
        @if (trim((string) $bid->proposal->qualify_summary) !== '')
            <span class="fw-light">{{ $bid->proposal->qualify_summary }}</span>
        @else
            <span class="text-muted fst-italic">No summary available</span>
        @endif
    @endif

    <div class="divider divider-primary"><div class="divider-text">Bid</div></div>
    <h6>Coverletter</h6>
    <span class="fw-light">{{ $bid->cover_letter }}</span>

    <div class="mt-4">
        <a href="{{ rtrim(config('variables.flBase'), '/') }}/projects/{{ $bid->proposal->project_id }}" target="_blank" class="btn btn-primary w-100">
            View on Freelancer
        </a>
    </div>
</div>
