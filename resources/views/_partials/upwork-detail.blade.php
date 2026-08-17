@php
    $rate = $job->job_type === 'hourly'
        ? trim(($job->hourly_min ? '$'.rtrim(rtrim(number_format($job->hourly_min, 2), '0'), '.') : '') .
               ($job->hourly_max ? ' – $'.rtrim(rtrim(number_format($job->hourly_max, 2), '0'), '.') : '')) . ' /hr'
        : ($job->budget_amount ? ($job->currency ? $job->currency.' ' : '$').number_format($job->budget_amount, 0) : '—');
    $rate = trim($rate) === '/hr' ? '—' : $rate;
@endphp
<div class="offcanvas-header">
    <h5 class="offcanvas-title d-flex align-items-center gap-2">
        <i class="bx bxl-upwork" style="color:#14a800;"></i> Upwork Job
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body" data-upwork-id="{{ $job->id }}">
    <span class="badge bg-label-{{ $job->job_type === 'hourly' ? 'info' : 'primary' }} text-uppercase">
        {{ $job->job_type ?? 'fixed' }}
    </span>

    <h6 class="mt-3">Title:</h6>
    <span class="fw-light">{{ $job->title ?? 'Untitled' }}</span>

    <h6 class="mt-3">{{ $job->job_type === 'hourly' ? 'Hourly Rate' : 'Budget' }}:
        <span class="fw-light">{{ $rate }}</span>
    </h6>
    <h6>Posted:
        <span class="fw-light">
            {{ $job->posted_at ? $job->posted_at->timezone('Asia/Karachi')->format('d-M, Y h:i a') : '—' }}
        </span>
    </h6>

    <div class="divider divider-primary"><div class="divider-text">Skills</div></div>
    @forelse (($job->skills ?? []) as $skill)
        <span class="badge bg-label-primary me-1 mb-1">{{ $skill }}</span>
    @empty
        <span class="text-muted fst-italic">No skills listed</span>
    @endforelse

    <div class="divider divider-primary"><div class="divider-text">Client</div></div>
    <h6>Country:
        <span class="fw-light">{{ $job->client_country ?? '—' }}</span>
        @if ($job->client_payment_verified)
            <i class="bx bx-badge-check text-success" title="Payment verified"></i>
        @endif
    </h6>
    <h6>Total Spent:
        <span class="fw-light">{{ $job->client_total_spent ? '$'.number_format($job->client_total_spent, 0) : '—' }}</span>
    </h6>

    <div class="divider divider-primary"><div class="divider-text">Description</div></div>
    <span class="fw-light" style="white-space: pre-line;">{{ $job->description ?: '—' }}</span>

    @if ($job->url)
        <div class="mt-4">
            <a href="{{ $job->url }}" target="_blank" rel="noopener" class="btn btn-primary w-100">
                View on Upwork
            </a>
        </div>
    @endif
</div>
