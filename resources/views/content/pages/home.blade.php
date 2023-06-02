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
        if ($bid->is_seen) {
            return 'fa fa-eye text-success fa-sm';
        } else {
            return 'fa fa-eye fa-sm';
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

    <head>
        <script>
            function copyId(id) {
                // Create a temporary input element
                var tempInput = document.createElement('input');

                // Set the value of the input element to the ID text
                tempInput.value = id;

                // Append the input element to the document body
                document.body.appendChild(tempInput);

                // Select the text in the input element
                tempInput.select();

                // Copy the selected text to the clipboard
                document.execCommand('copy');

                // Remove the temporary input element
                document.body.removeChild(tempInput);
            }
        </script>
    </head>
    <h4 class="py-3 breadcrumb-wrapper mb-4">
        <span class="fw-light">Bids</span>
    </h4>

    <form action="{{ route('expire_bids') }}" method="POST">
        @csrf
        <div class="container">
            <button type="Save" name="submitButton" class="btn btn-danger mb-4" style="float:right;">Expire Pending</button>
        </div>
    </form>

    <div class="card container">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Time</th>
                    <th>Review</th>
                </tr>
            </thead>
            <tbody>

                @foreach ($bids as $bid)
                    <tr>
                        <td>{{ $bid->proposal->project_id }}</td>
                        <td>{{ Illuminate\Support\Str::limit($bid->proposal->title, 30) }}</td>
                        <td>{{ $bid->price }}$ - {{ $bid->proposal->country }}</td>
                        <td><span class="badge {{ pending($bid) }} me-1">{{ $bid->bid_status }}</span></td>
                        <td>{{ $bid->proposal->type }}</td>
                        <td>
                            <div class="col">
                                <div class="row">
                                    {{ $bid->proposal->created_at->format('h:i a') }}
                                </div>
                                <div class="row text-light">
                                    {{ $bid->proposal->created_at->diffForHumans(null, true) }}
                                </div>

                            </div>
                        </td>
                        <td>
                            <a href="{{ route('bids.show', ['bid' => $bid->id]) }}"\>
                                <i class="{{ eye($bid) }} px-2"></i>
                            </a>
                            <i class=" {{ reviewdCheckColorClass($bid) }}"></i>
                            <a href="https://www.freelancer.com/projects/{{ $bid->proposal->project_id }}" target="_blank">
                                <i class="bx bx-link-external text-primary px-2"></i>
                            </a>
                            <i class='bx bx-copy' onclick="copyId({{ $bid->id }})"></i>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="div mt-4 card px-4 pt-3 Page navigation">
        {{ $bids->links('vendor.pagination.bootstrap-5') }}
    </div>
@endsection
