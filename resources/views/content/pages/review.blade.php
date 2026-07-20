@extends('layouts.layoutMaster')

@section('title', 'Review')

@section('content')
    <h4 class="page-title">Review</h4>

    <ul class="nav nav-tabs mb-4" id="review-tabs">
        <li class="nav-item">
            <button type="button" class="nav-link active" data-tab="new">
                New Projects <span class="badge bg-label-primary ms-1" id="count-new">{{ $newCount }}</span>
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link" data-tab="old">
                Old Projects <span class="badge bg-label-secondary ms-1" id="count-old">{{ $oldCount }}</span>
            </button>
        </li>
    </ul>

    <div id="review-list">
        @foreach ($proposals as $proposal)
            @include('_partials.review-card', ['proposal' => $proposal])
        @endforeach
    </div>

    <div id="review-sentinel" class="py-3 text-center text-muted" data-has-more="{{ $hasMore ? '1' : '0' }}"></div>

    <div id="review-empty" class="py-5 text-center text-muted"
         style="{{ $proposals->count() === 0 ? '' : 'display:none;' }}">
        All projects reviewed 🎉
    </div>
@endsection

@section('page-script')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('review-list');
    const sentinel = document.getElementById('review-sentinel');
    const empty = document.getElementById('review-empty');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let currentTab = 'new';
    let loading = false;

    function showToast(title, message, color) {
        let container = document.getElementById('review-toasts');
        if (!container) {
            container = document.createElement('div');
            container.id = 'review-toasts';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }
        const el = document.createElement('div');
        el.className = 'toast bg-white border-0 shadow-lg rounded-3 overflow-hidden';
        el.setAttribute('role', 'alert');
        el.style.borderLeft = '4px solid ' + color;
        el.style.minWidth = '320px';
        el.innerHTML =
            '<div class="d-flex align-items-center p-3">' +
            '<span class="badge rounded-circle p-2 me-3 lh-1" style="color:' + color + ';background:' + color + '20">' +
            '<i class="bx bx-check bx-sm"></i></span>' +
            '<div class="me-3"><div class="fw-semibold text-body"></div>' +
            '<small class="text-muted"></small></div>' +
            '<button type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        el.querySelector('.fw-semibold').textContent = title;
        el.querySelector('small').textContent = message;
        container.appendChild(el);
        el.addEventListener('hidden.bs.toast', () => el.remove());
        new bootstrap.Toast(el, { delay: 3000 }).show();
    }

    const labelToasts = {
        relevant: { title: 'Marked Relevant', color: '#28c76f' },
        not_relevant_skill: { title: 'Marked Not Relevant Skill', color: '#ffab00' },
        scam: { title: 'Marked Scam', color: '#ff3e1d' },
    };

    function hasMore() { return sentinel.dataset.hasMore === '1'; }

    function activeCountEl() {
        return document.getElementById(currentTab === 'old' ? 'count-old' : 'count-new');
    }

    function maybeShowEmpty() {
        if (!list.querySelector('.review-card') && !hasMore()) {
            empty.style.display = '';
            sentinel.style.display = 'none';
        }
    }

    function lastProposalId() {
        const cards = list.querySelectorAll('.review-card');
        return cards.length ? cards[cards.length - 1].dataset.proposalId : '';
    }

    function loadMore() {
        if (loading || !hasMore()) return;
        loading = true;
        const afterId = lastProposalId();
        fetch(`/review/load?tab=${currentTab}&after_id=${afterId}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                list.insertAdjacentHTML('beforeend', data.html);
                sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
                loading = false;
                if (!hasMore()) maybeShowEmpty();
            })
            .catch(() => { loading = false; });
    }

    function switchTab(tab) {
        if (tab === currentTab) return;
        loading = false;
        currentTab = tab;
        document.querySelectorAll('#review-tabs .nav-link').forEach(b =>
            b.classList.toggle('active', b.dataset.tab === tab));
        list.innerHTML = '';
        empty.style.display = 'none';
        sentinel.style.display = '';
        sentinel.dataset.hasMore = '1';
        loadMore();
    }

    document.getElementById('review-tabs').addEventListener('click', function (e) {
        const btn = e.target.closest('.nav-link');
        if (btn) switchTab(btn.dataset.tab);
    });

    const observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) loadMore();
    });
    observer.observe(sentinel);

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.review-btn');
        if (!btn) return;
        const card = btn.closest('.review-card');
        const proposalId = card.dataset.proposalId;
        const label = btn.dataset.label;

        card.querySelectorAll('.review-btn').forEach(b => b.disabled = true);

        fetch('/review/feedback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ proposal_id: proposalId, label: label }),
        })
            .then(r => { if (!r.ok) throw new Error('failed'); return r.json(); })
            .then(() => {
                const t = labelToasts[label] || { title: 'Feedback saved', color: '#28c76f' };
                showToast(t.title, 'Feedback saved for this project.', t.color);
                const badge = activeCountEl();
                if (badge) badge.textContent = Math.max(0, parseInt(badge.textContent || '0', 10) - 1);
                card.style.transition = 'opacity .2s';
                card.style.opacity = '0';
                setTimeout(() => { card.remove(); maybeShowEmpty(); if (hasMore()) loadMore(); }, 200);
            })
            .catch(() => {
                showToast('Failed', 'Could not save feedback — try again.', '#ff3e1d');
                card.querySelectorAll('.review-btn').forEach(b => b.disabled = false);
            });
    });
});
</script>
@endsection
