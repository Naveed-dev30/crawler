@extends('layouts.layoutMaster')

@php
    function reviewdCheckColorClass($bid)
    {
        if ($bid->check == 'Unreviewed') {
            return 'fa fa-warning text-warning';
        } elseif ($bid->check == 'Correct') {
            return 'fa fa-check text-success';
        } elseif ($bid->check == 'Incorrect') {
            return 'fa fa-close text-danger';
        }
    }
    
    function eye($bid)
    {
        if ($bid->check == 'Unreviewed') {
            return 'fa fa-eye fa-sm';
        } else {
            return 'fa fa-eye text-success fa-sm';
        }
    }
    
    function pending($bid)
    {
        if ($bid->bid_status == 'completed') {
            return 'bg-label-success';
        } elseif ($bid->bid_status == 'pending') {
            return 'bg-label-primary';
        } else {
            return 'bg-label-danger';
        }
    }
    
@endphp

@section('content')
    <h4 class="py-3 breadcrumb-wrapper mb-4">
        <span class="fw-light">Bids</span>
    </h4>
    <div class=" card">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Country</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Time</th>
                    <th>Open Project</th>
                    <th>Review</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

                @foreach ($bids as $bid)
                    <tr>
                        <td>{{ $bid->id }}</td>
                        <td>{{ $bid->proposal->country }}</td>
                        <td>{{ $bid->price }}$</td>
                        <td><span class="badge {{ pending($bid) }} me-1">{{ $bid->bid_status }}</span></td>
                        <td>{{ $bid->proposal->type }}</td>
                        <td>{{ $bid->proposal->created_at->diffForHumans() }}</td>
                        <td>
                            <a class="dropdown-item"
                                href="https://www.freelancer.com/projects/{{ $bid->proposal->project_id }}">
                                <i class="bx  me-1"></i> {{ $bid->proposal->project_id }}
                        </td>
                        <td>
                            <i class="{{ eye($bid) }} px-2"></i>
                            <i class=" {{ reviewdCheckColorClass($bid) }}"></i>
                        </td>
                        <td>
                            <a class="dropdown-item" href="{{ route('bids.show', ['bid' => $bid->id]) }}">
                                <i class="bx bx-edit-alt me-1"></i>View
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
