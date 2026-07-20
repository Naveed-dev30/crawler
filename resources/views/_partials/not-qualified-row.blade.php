<tr>
    <td>{{ $proposal->project_id }}</td>
    <td>{{ \Illuminate\Support\Str::limit($proposal->title, 40) }}</td>
    <td><span class="fw-bold">{{ $proposal->qualify_reason }}</span></td>
    <td>
        @if (trim((string) $proposal->qualify_summary) !== '')
            <span class="fw-light">{{ $proposal->qualify_summary }}</span>
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
