@extends('layouts.layoutMaster')

@php
    $reviewdCheckbgClass = '';
    if ($bid->check == 'Unreviewed') {
        $reviewdCheckbgClass = 'bg-warning';
    } elseif ($bid->check == 'Correct') {
        $reviewdCheckbgClass = 'bg-success';
    } elseif ($bid->check == 'Incorrect') {
        $reviewdCheckbgClass = 'bg-danger';
    }
@endphp

@section('content')
    <div class="mb-4">
        <i onclick="window.history.back()" style="cursor: pointer;" class="bx bx-left-arrow-circle bx-md"></i>
    </div>

    <h4 class="py-3 breadcrumb-wrapper mb-4">
        <span class="fw-light">Bid/{{ $bid->id }}</span>
    </h4>

    <div class="card p-4">
        <span>
            <p class="badge {{ $reviewdCheckbgClass }}">{{ $bid->check }}</p>
        </span>
        <p>Last Updated: {{ $bid->proposal->updated_at->format('d-M, Y') }}</p>

        <h6>Project Min Budget: <span class="fw-light">{{ $bid->proposal->min_budget }}$</span></h6>

        <h6>Project Max Budget: <span class="fw-light">{{ $bid->proposal->max_budget }}$</span></h6>

        <h6>Project Quoted: <span class="fw-light">{{ $bid->price }}</span></h6>


        <h6>Bid Status: <span class="fw-light">{{ $bid->bid_status }}</span></h6>

        <h6>Type: <span class="fw-light">{{ $bid->proposal->type }}</span></h6>

        <div class="divider divider-primary">
            <div class="divider-text">Title/Description</div>
        </div>

        <h6>Title: </h6>
        <span class="fw-light">{{ $bid->proposal->title }}</span>



        <h6 class="mt-4">Project Description: </h6>
        <span class="fw-light">{{ $bid->proposal->description }}</span>

        <div class="divider divider-primary">
            <div class="divider-text">Bid</div>
        </div>

        <h6>Coverletter</h6>
        <span class="fw-light">{{ $bid->cover_letter }}</span>

        <form action="/updateBidCheck" method="post">
            @csrf
            <div class="row mt-4">
                <input type="hidden" name="bid_id" value="{{ $bid->id }}">
                <span>
                    <span>
                        <button class="btn btn-primary" name="check" value="Correct">Correct</button>
                    </span>
                    <span>
                        <button class="btn btn-danger" name="check" value="Incorrect">Incorrect</button>
                    </span>
                </span>
            </div>
        </form>
    </div>
@endsection
