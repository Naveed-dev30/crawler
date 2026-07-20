<div class="offcanvas-header">
    <h5 class="offcanvas-title">Project #{{ $proposal->project_id }}</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body">
    <span class="badge bg-label-info">Not Qualified</span>

    <p class="mt-3 mb-1">Crawled: {{ $proposal->created_at->copy()->timezone('Asia/Karachi')->format('d-M, Y h:i a') }}</p>
    <h6>Project Min Budget: <span class="fw-light">{{ $proposal->min_budget }}$</span></h6>
    <h6>Project Max Budget: <span class="fw-light">{{ $proposal->max_budget }}$</span></h6>
    <h6>Type: <span class="fw-light">{{ $proposal->type }}</span></h6>

    <div class="divider divider-primary"><div class="divider-text">Title/Description</div></div>
    <h6>Title:</h6>
    <span class="fw-light">{{ $proposal->title }}</span>
    <h6 class="mt-4">Project Description:</h6>
    <span class="fw-light" style="white-space: pre-line;">{{ $proposal->description }}</span>

    <div class="divider divider-primary"><div class="divider-text">Qualification</div></div>
    <h6>Reason:</h6>
    <span class="fw-bold">{{ $proposal->qualify_reason }}</span>
    <h6 class="mt-3">Summary:</h6>
    @if (trim((string) $proposal->qualify_summary) !== '')
        <span class="fw-light">{{ $proposal->qualify_summary }}</span>
    @else
        <span class="text-muted fst-italic">No summary available</span>
    @endif

    <div class="mt-4">
        <a href="https://www.freelancer.com/projects/{{ $proposal->project_id }}" target="_blank" rel="noopener"
           class="btn btn-primary w-100">
            View on Freelancer
        </a>
    </div>
</div>
