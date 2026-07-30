{{-- expects: $title, $metric, $value, $chartType ('line'|'bar'), $reversed (bool), $min, $max --}}
<div class="card h-100"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <span class="text-muted">{{ $title }}</span>
            <h3 class="fw-bold mb-0" data-metric-value="{{ $metric }}">{{ $value }}</h3>
        </div>
        <div class="insight-trend-filter"
             data-metric="{{ $metric }}" data-type="{{ $chartType }}" data-reversed="{{ $reversed ? '1' : '0' }}">
            <label class="form-label small text-muted mb-0">Since</label>
            <input type="date" class="form-control form-control-sm trend-date" style="width:9.5rem"
                   min="{{ $min }}" max="{{ $max }}">
        </div>
    </div>
    <div data-metric-chart="{{ $metric }}"></div>
</div></div>
