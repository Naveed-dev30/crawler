<tr>
    <td>{{ $proposal->project_id }}</td>
    <td>{{ \Illuminate\Support\Str::limit($proposal->title, 40) }}</td>
    <td class="nq-wrap"><span class="fw-bold" title="{{ $proposal->qualify_reason }}">{{ $proposal->qualify_reason }}</span></td>
    <td class="nq-wrap">
        @if (trim((string) $proposal->qualify_summary) !== '')
            <span class="fw-light" title="{{ $proposal->qualify_summary }}">{{ $proposal->qualify_summary }}</span>
        @else
            <span class="text-muted fst-italic">No summary available</span>
        @endif
    </td>
    <td>{{ $proposal->created_at->diffForHumans(null, true) }}</td>
    <td>
        <a href="https://www.freelancer.com/projects/{{ $proposal->project_id }}" target="_blank" rel="noopener"
           class="btn btn-sm btn-label-primary">
            <i class="fa fa-external-link me-1"></i> View
        </a>
    </td>
</tr>
