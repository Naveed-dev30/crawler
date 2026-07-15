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
    <span class="badge {{ $checkBadge }}" data-check-badge>{{ $bid->check }}</span>

    <p class="mt-3 mb-1">Last Updated: {{ $bid->proposal->updated_at->format('d-M, Y') }}</p>
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

    <div class="divider divider-primary"><div class="divider-text">Bid</div></div>
    <h6>Coverletter</h6>
    <span class="fw-light">{{ $bid->cover_letter }}</span>

    <div class="mt-4 d-flex flex-column gap-2">
        <a href="https://www.freelancer.com/projects/{{ $bid->proposal->project_id }}" target="_blank" class="btn btn-primary">
            View on Freelancer
        </a>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success bid-check-btn" data-bid-id="{{ $bid->id }}" data-check="Correct">Correct</button>
            <button type="button" class="btn btn-outline-danger bid-check-btn" data-bid-id="{{ $bid->id }}" data-check="Incorrect">Incorrect</button>
        </div>
    </div>
</div>
