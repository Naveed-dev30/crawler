<div class="card mb-4 relevance-card" data-bid-id="{{ $bid->id }}">
    <div class="card-body">
        <h6 class="fw-bold mb-1">Title:</h6>
        <p class="mb-3">{{ optional($bid->proposal)->title ?? '(no project data)' }}</p>

        <h6 class="fw-bold mb-1">Project Description:</h6>
        <p class="text-muted mb-4">{{ optional($bid->proposal)->description }}</p>

        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-success relevance-btn" data-feedback="relevant">Relevant</button>
            <button type="button" class="btn btn-warning relevance-btn" data-feedback="irrelevant">Irrelevant</button>
            <button type="button" class="btn btn-danger relevance-btn" data-feedback="scam">Scam</button>
        </div>
    </div>
</div>
