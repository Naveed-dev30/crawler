{{-- resources/views/_partials/bid-actions-taken.blade.php

     Freelancer's own "Actions Taken" trio — has the client seen the bid, opened
     the profile, rated the bid. Solid green once the client takes the action,
     hollow grey until then. Shared by Bid Insights and the Opportunities table
     so the same three icons mean the same thing on both pages.

     Params:
       $insight  BidInsight|null — null until the insights scraper has visited
                 the project, in which case there is nothing to report yet
--}}
@php
    $insight = $insight ?? null;
    $actions = $insight ? [
        ['on' => 'bxs-show', 'off' => 'bx-show', 'taken' => $insight->clientSawBid(), 'label' => 'seen your bid', 'note' => null],
        ['on' => 'bxs-user', 'off' => 'bx-user', 'taken' => $insight->clientSawProfile(), 'label' => 'viewed your profile', 'note' => null],
        ['on' => 'bxs-check-circle', 'off' => 'bx-check-circle', 'taken' => $insight->clientRatedBid(), 'label' => 'rated your bid',
         'note' => $insight->bid_rating ? ' ('.number_format($insight->bid_rating, 1).')' : null],
    ] : [];
@endphp
@if ($insight)
    <div class="d-flex gap-2 fs-5">
        @foreach ($actions as $action)
            {{-- data-bs-toggle is what main.js scans for to build the styled
                 tooltip; a bare title only gets the native browser one. --}}
            <i class="bx {{ $action['taken'] ? $action['on'].' text-success' : $action['off'].' text-muted' }}"
               data-bs-toggle="tooltip" data-bs-placement="top"
               title="Client has {{ $action['taken'] ? '' : 'not ' }}{{ $action['label'] }}{{ $action['taken'] ? $action['note'] : '' }}"></i>
        @endforeach
    </div>
@else
    <span class="text-muted">—</span>
@endif
