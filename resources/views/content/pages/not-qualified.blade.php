@extends('layouts.layoutMaster')

@section('title', 'Not Qualified')

@section('content')
    <h4 class="page-title">Not Qualified</h4>

    <div class="card">
        <div class="table-responsive text-nowrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Title</th>
                        <th>Reason</th>
                        <th>Summary</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proposals as $proposal)
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
                                <a href="https://www.freelancer.com/projects/{{ $proposal->project_id }}" target="_blank" class="btn btn-sm btn-label-primary">
                                    <i class="fa fa-external-link me-1"></i> View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No not-qualified proposals yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $proposals->links('vendor.pagination.bootstrap-5') }}
    </div>
@endsection
