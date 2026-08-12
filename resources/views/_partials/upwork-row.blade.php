@php
    $budget = $job->job_type === 'hourly'
        ? trim(($job->hourly_min ? '$'.rtrim(rtrim(number_format($job->hourly_min, 2), '0'), '.') : '') .
               ($job->hourly_max ? ' – $'.rtrim(rtrim(number_format($job->hourly_max, 2), '0'), '.') : '') . '/hr')
        : ($job->budget_amount ? ($job->currency ? $job->currency.' ' : '$').number_format($job->budget_amount, 0) : '—');
    $budget = ($budget === '' || trim($budget) === '/hr') ? '—' : $budget;
@endphp
<tr>
    <td>
        <a href="{{ $job->url }}" target="_blank" rel="noopener" class="fw-semibold text-body">
            {{ $job->title ?? 'Untitled' }}
        </a>
    </td>
    <td class="text-nowrap">{{ $budget }}</td>
    <td class="text-nowrap">{{ $job->posted_at?->diffForHumans() ?? '—' }}</td>
    <td>
        @foreach (($job->skills ?? []) as $skill)
            <span class="badge bg-label-primary me-1 mb-1">{{ $skill }}</span>
        @endforeach
    </td>
    <td class="text-nowrap">
        {{ $job->client_country ?? '—' }}
        @if ($job->client_payment_verified)
            <i class="bx bx-badge-check text-success" title="Payment verified"></i>
        @endif
        @if ($job->client_total_spent)
            <div class="text-muted small">${{ number_format($job->client_total_spent, 0) }} spent</div>
        @endif
    </td>
    <td class="text-nowrap text-end">
        <button type="button" class="btn btn-sm btn-outline-primary upwork-view-btn" data-upwork-id="{{ $job->id }}">
            <i class="bx bx-show me-1"></i>View
        </button>
    </td>
</tr>
