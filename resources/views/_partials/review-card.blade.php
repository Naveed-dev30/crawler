<div class="card mb-4 review-card" data-proposal-id="{{ $proposal->id }}">
    <div class="card-body">
        <h6 class="fw-bold mb-1">Title:</h6>
        <p class="mb-3">{{ $proposal->title ?? '(no title)' }}</p>

        <h6 class="fw-bold mb-1">Project Description:</h6>
        <p class="text-muted mb-3">{{ $proposal->description }}</p>

        <div class="mb-3">
            @foreach (($proposal->skills ?? []) as $skill)
                <span class="badge bg-label-primary me-1">{{ $skill }}</span>
            @endforeach
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="text-muted small">
                {{ $proposal->min_budget }} {{ $proposal->currency_name }} · {{ $proposal->type }} · {{ $proposal->country }}
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success review-btn" data-label="relevant">Relevant</button>
                <button type="button" class="btn btn-warning review-btn" data-label="not_relevant_skill">Not Relevant Skill</button>
                <button type="button" class="btn btn-danger review-btn" data-label="scam">Scam</button>
            </div>
        </div>
    </div>
</div>
