{{-- resources/views/_partials/chat-project-details.blade.php

     Everything we know about the project behind this thread, so an agent can
     answer a client without leaving the chat: the Freelancer listing, our bid,
     and the client profile. Long text sits behind a toggle — the conversation
     is what the panel is for, and a full description would push it off screen.
--}}
@php
    $p = $thread->proposal;
    $bid = $p?->bid;
    $symbol = $p?->currency_symbol ?: '$';
    $skills = collect($p?->skills ?? [])->filter()->values();
    $posted = $p?->project_added_time ? \Carbon\Carbon::createFromTimestamp($p->project_added_time) : null;
    $hasLongText = filled($p?->description) || filled($bid?->cover_letter)
        || filled($p?->qualify_reason) || filled($p?->qualify_summary);

    // Trim trailing zeros so 250.00 reads as 250, and build the range as one
    // string — split across Blade lines it would render with stray whitespace.
    $money = fn ($amount) => $symbol.rtrim(rtrim(number_format((float) $amount, 2), '0'), '.');
    $budget = ($p?->min_budget || $p?->max_budget)
        ? $money($p->min_budget).' – '.$money($p->max_budget)
        : null;

@endphp

<div class="card shadow-none border mb-4">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="bx bx-briefcase-alt me-1"></i>Project Details</h6>
            <a href="{{ rtrim(config('variables.flBase'), '/') }}/projects/{{ $thread->project_id }}"
               target="_blank" rel="noopener" class="btn btn-sm btn-label-primary text-nowrap">
                <i class="bx bx-link-external me-1"></i>On Freelancer
            </a>
        </div>

        @if (! $p)
            <p class="text-muted small mb-0">
                No proposal is linked to this thread, so we have nothing beyond the
                project id — the client messaged about a project we did not bid on.
            </p>
        @else
            <dl class="row mb-0 small">
                <dt class="col-5 fw-semibold">Budget</dt>
                <dd class="col-7">
                    @if ($budget)
                        {{ $budget }}
                        @if ($p->currency_name)
                            <span class="text-muted">{{ $p->currency_name }}</span>
                        @endif
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </dd>

                <dt class="col-5 fw-semibold">Type</dt>
                <dd class="col-7">{{ $p->type ? ucfirst($p->type) : '—' }}</dd>

                <dt class="col-5 fw-semibold">Client country</dt>
                <dd class="col-7">{{ $p->country ?: '—' }}</dd>

                @if ($p->language)
                    <dt class="col-5 fw-semibold">Language</dt>
                    <dd class="col-7">{{ strtoupper($p->language) }}</dd>
                @endif

                @if ($posted)
                    <dt class="col-5 fw-semibold">Posted</dt>
                    <dd class="col-7">{{ $posted->timezone('Asia/Karachi')->format('M j, Y H:i') }}</dd>
                @endif

                @if ($skills->isNotEmpty())
                    <dt class="col-5 fw-semibold">Skills</dt>
                    <dd class="col-7">
                        @foreach ($skills as $skill)
                            <span class="badge bg-label-secondary mb-1">{{ $skill }}</span>
                        @endforeach
                    </dd>
                @endif

                @if (! is_null($p->qualified))
                    <dt class="col-5 fw-semibold">Qualified</dt>
                    <dd class="col-7">
                        <span class="badge {{ $p->qualified ? 'bg-label-success' : 'bg-label-danger' }}">
                            {{ $p->qualified ? 'Yes' : 'No' }}
                        </span>
                    </dd>
                @endif
            </dl>

            {{-- Our bid --}}
            @if ($bid)
                <hr class="my-3">
                <dl class="row mb-0 small">
                    <dt class="col-5 fw-semibold">Our bid</dt>
                    <dd class="col-7">
                        {{ $money($bid->price) }}
                        <span class="badge bg-label-{{ strtolower((string) $bid->bid_status) === 'failed' ? 'danger' : 'info' }} ms-1">
                            {{ ucfirst((string) $bid->bid_status) }}
                        </span>
                    </dd>

                    @if ($bid->posted_at)
                        <dt class="col-5 fw-semibold">Bid placed</dt>
                        <dd class="col-7">{{ $bid->posted_at->timezone('Asia/Karachi')->format('M j, Y H:i') }}</dd>
                    @endif

                    @if ($bid->awarded)
                        <dt class="col-5 fw-semibold">Awarded</dt>
                        <dd class="col-7">
                            {{-- Directive on its own line: Blade will not compile
                                 an @if that follows a word character. --}}
                            <span class="badge bg-label-success">
                                Yes
                                @if ($bid->awarded_price)
                                    · {{ $money($bid->awarded_price) }}
                                @endif
                            </span>
                        </dd>
                    @endif

                    @if (strtolower((string) $bid->bid_status) === 'failed' && $bid->error_message)
                        <dt class="col-5 fw-semibold">Bid error</dt>
                        <dd class="col-7 text-danger">{{ $bid->error_message }}</dd>
                    @endif
                </dl>
            @endif

            @if ($insight || $p->project_owner)
                <hr class="my-3">
                @include('_partials.client-profile', [
                    'insight' => $insight,
                    'ownerId' => $p->project_owner,
                    'fallbackCountry' => $p->country,
                ])
            @endif

            {{-- Where our bid stands against the field --}}
            @if ($insight?->bid_rank || $insight?->winning_bid_amount)
                <hr class="my-3">
                <dl class="row mb-0 small">
                    @if ($insight->bid_rank)
                        <dt class="col-5 fw-semibold">Our bid rank</dt>
                        <dd class="col-7">
                            #{{ $insight->bid_rank }}@if ($insight->total_bids)
                                <span class="text-muted">of {{ number_format($insight->total_bids) }}</span>
                            @endif
                        </dd>
                    @endif

                    @if ($insight->winning_bid_amount)
                        <dt class="col-5 fw-semibold">Winning bid</dt>
                        <dd class="col-7">
                            {{ $insight->bid_currency ?: $symbol }}{{ rtrim(rtrim(number_format((float) $insight->winning_bid_amount, 2), '0'), '.') }}
                            @if ($insight->winning_bid_sealed)
                                <span class="text-muted">(sealed)</span>
                            @endif
                        </dd>
                    @endif
                </dl>
            @endif

            @if ($hasLongText)
                <button class="btn btn-sm btn-label-secondary w-100 mt-3" type="button"
                        id="chat-project-more-btn" data-bs-toggle="collapse"
                        data-bs-target="#chat-project-more" aria-expanded="false">
                    <i class="bx bx-chevron-down me-1"></i>Description, cover letter &amp; qualification
                </button>
                <div class="collapse mt-3" id="chat-project-more">
                    @if (filled($p->description))
                        <h6 class="small fw-semibold mb-1">Project description</h6>
                        <p class="small text-muted" style="white-space: pre-wrap;">{{ $p->description }}</p>
                    @endif

                    @if (filled($bid?->cover_letter))
                        <h6 class="small fw-semibold mb-1">Our cover letter</h6>
                        <p class="small text-muted" style="white-space: pre-wrap;">{{ $bid->cover_letter }}</p>
                    @endif

                    @if (filled($p->qualify_reason) || filled($p->qualify_summary))
                        <h6 class="small fw-semibold mb-1">Qualification</h6>
                        @if (filled($p->qualify_reason))
                            <p class="small mb-1">{{ $p->qualify_reason }}</p>
                        @endif
                        @if (filled($p->qualify_summary))
                            <p class="small text-muted mb-0" style="white-space: pre-wrap;">{{ $p->qualify_summary }}</p>
                        @endif
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
