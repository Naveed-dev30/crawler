{{-- resources/views/_partials/client-profile.blade.php

     Who posted the project on Freelancer. Shared by the Chats project panel and
     the bid detail slide-over, so both read the same regardless of where you
     arrived from.

     Params:
       $insight        BidInsight|null — name/avatar/reputation, null until the
                       crawler or thread sync has resolved this client

     Freelancer's API returns the client's country, rating, member-since and
     verification badges for anyone, but not their id or name — `owner_id` has
     come back null on projects/active, the single-project endpoint and the bids
     endpoint since Jan 2024. The name is recovered from the message thread's
     member list (ThreadSyncer) once a conversation exists, so a bid nobody has
     replied to legitimately has no name to show.
       $ownerId        int|null        — proposals.project_owner
       $fallbackCountry string|null    — proposal country, used when the client
                       profile itself has none
--}}
@php
    $insight = $insight ?? null;
    $ownerId = $ownerId ?? null;
    $fallbackCountry = $fallbackCountry ?? null;

    // Avatar and flag come straight from Freelancer's CDN, so treat them as
    // untrusted: only http(s) is allowed through, and the protocol-relative
    // form their API returns is normalised rather than dropped.
    $safeImage = function (?string $url): ?string {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? $url : null;
    };

    $clientName = $insight?->client_name;
    $clientAvatar = $safeImage($insight?->client_avatar);
    $clientFlag = $safeImage($insight?->client_country_flag);
    $clientInitials = $clientName
        ? collect(explode(' ', $clientName))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('')
        : '?';

    // Freelancer's `status` object: only the badges that are actually true.
    $verificationLabels = [
        'identity_verified' => 'Identity',
        'payment_verified' => 'Payment',
        'email_verified' => 'Email',
        'phone_verified' => 'Phone',
        'deposit_made' => 'Deposit',
        'profile_complete' => 'Profile complete',
    ];
    $verified = collect($insight?->client_verification ?? [])
        ->filter()
        ->keys()
        ->map(fn ($k) => $verificationLabels[$k] ?? ucfirst(str_replace('_', ' ', $k)));

    $engagement = collect($insight?->client_engagement ?? [])->filter(fn ($v) => $v !== null);
@endphp

@if ($insight || $ownerId)
    <h6 class="small fw-semibold mb-2">Posted by</h6>

    <div class="d-flex align-items-center gap-2 mb-2">
        <div class="avatar avatar-sm flex-shrink-0">
            @if ($clientAvatar)
                <img src="{{ $clientAvatar }}" alt="{{ $clientName }}" class="rounded-circle">
            @else
                <span class="avatar-initial rounded-circle bg-label-secondary">{{ $clientInitials }}</span>
            @endif
        </div>
        <div class="min-w-0">
            <div class="fw-semibold lh-sm">
                {{-- Only the username builds a valid profile url; display names
                     contain spaces. --}}
                @if ($insight?->client_username)
                    <a href="{{ rtrim(config('variables.flBase'), '/') }}/u/{{ $insight->client_username }}"
                       target="_blank" rel="noopener">{{ $clientName ?: $insight->client_username }}</a>
                @elseif ($clientName)
                    {{ $clientName }}
                @else
                    {{-- Not missing data: Freelancer's API has withheld the
                         project owner's id and name since Jan 2024, so the name
                         only arrives with the message thread once the client
                         opens a chat. Say that, rather than look broken. --}}
                    <span class="text-muted">Name withheld by Freelancer</span>
                @endif
            </div>
            <small class="text-muted">
                @if ($clientFlag)
                    <img src="{{ $clientFlag }}" alt="" width="14" class="me-1">
                @endif
                {{ $insight?->client_country ?: $fallbackCountry ?: 'Country unknown' }}
            </small>
            @if (! $clientName && ! $insight?->client_username)
                <small class="d-block text-muted fst-italic">Shown once the client starts a chat</small>
            @endif
        </div>
    </div>

    <dl class="row mb-0 small">
        @if ($ownerId)
            <dt class="col-5 fw-semibold">Freelancer ID</dt>
            <dd class="col-7">{{ $ownerId }}</dd>
        @endif

        @if ($insight?->client_rating)
            <dt class="col-5 fw-semibold">Rating</dt>
            <dd class="col-7">
                <i class="bx bxs-star text-warning"></i>
                {{ number_format((float) $insight->client_rating, 1) }}
                @if ($insight->client_reviews)
                    <span class="text-muted">({{ $insight->client_reviews }} reviews)</span>
                @endif
            </dd>
        @endif

        @if ($insight?->client_member_since)
            <dt class="col-5 fw-semibold">Member since</dt>
            <dd class="col-7">{{ $insight->client_member_since->format('M Y') }}</dd>
        @endif

        @if ($verified->isNotEmpty())
            <dt class="col-5 fw-semibold">Verified</dt>
            <dd class="col-7">
                @foreach ($verified as $badge)
                    <span class="badge bg-label-success mb-1">
                        <i class="bx bx-check me-1"></i>{{ $badge }}
                    </span>
                @endforeach
            </dd>
        @endif

        @if ($engagement->isNotEmpty())
            <dt class="col-5 fw-semibold">History</dt>
            <dd class="col-7">
                @foreach ($engagement as $label => $value)
                    <span class="text-muted d-block">
                        {{ ucfirst(str_replace('_', ' ', $label)) }}: {{ $value }}
                    </span>
                @endforeach
            </dd>
        @endif
    </dl>
@endif
