@extends('layouts.layoutMaster')

@section('title', 'Relevance')

@section('content')
    <h4 class="page-title">Relevance</h4>

    <div id="relevance-list">
        @foreach ($bids as $bid)
            @include('_partials.relevance-card', ['bid' => $bid])
        @endforeach
    </div>

    <div id="relevance-sentinel" class="py-3 text-center text-muted" data-has-more="{{ $hasMore ? '1' : '0' }}"></div>

    <div id="relevance-empty" class="py-5 text-center text-muted"
         style="{{ $bids->count() === 0 ? '' : 'display:none;' }}">
        All bids reviewed 🎉
    </div>
@endsection

@section('page-script')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('relevance-list');
    const sentinel = document.getElementById('relevance-sentinel');
    const empty = document.getElementById('relevance-empty');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let loading = false;

    function hasMore() { return sentinel.dataset.hasMore === '1'; }

    function maybeShowEmpty() {
        if (!list.querySelector('.relevance-card') && !hasMore()) {
            empty.style.display = '';
            sentinel.style.display = 'none';
        }
    }

    function lastBidId() {
        const cards = list.querySelectorAll('.relevance-card');
        return cards.length ? cards[cards.length - 1].dataset.bidId : '';
    }

    function loadMore() {
        if (loading || !hasMore()) return;
        loading = true;
        const afterId = lastBidId();
        fetch(`/relevance/load?after_id=${afterId}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                list.insertAdjacentHTML('beforeend', data.html);
                sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
                loading = false;
                if (!hasMore()) maybeShowEmpty();
            })
            .catch(() => { loading = false; });
    }

    const observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) loadMore();
    });
    observer.observe(sentinel);

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.relevance-btn');
        if (!btn) return;
        const card = btn.closest('.relevance-card');
        const bidId = card.dataset.bidId;
        const feedback = btn.dataset.feedback;

        card.querySelectorAll('.relevance-btn').forEach(b => b.disabled = true);

        fetch('/relevance/feedback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ bid_id: bidId, feedback: feedback }),
        })
            .then(r => { if (!r.ok) throw new Error('failed'); return r.json(); })
            .then(() => {
                card.style.transition = 'opacity .2s';
                card.style.opacity = '0';
                setTimeout(() => { card.remove(); maybeShowEmpty(); if (hasMore()) loadMore(); }, 200);
            })
            .catch(() => {
                card.querySelectorAll('.relevance-btn').forEach(b => b.disabled = false);
            });
    });
});
</script>
@endsection
