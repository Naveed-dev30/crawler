@extends('layouts.layoutMaster')


@section('title', 'Filters')


@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/select2/select2.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/flatpickr/flatpickr.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/typeahead-js/typeahead.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/formvalidation/dist/css/formValidation.min.css') }}"/>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/moment/moment.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/flatpickr/flatpickr.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/typeahead-js/typeahead.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/FormValidation.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/plugins/Bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/plugins/AutoFocus.min.js') }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset('assets/js/form-validation.js') }}"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                new bootstrap.Popover(el);
            });

            const toast = document.getElementById('filters-toast');
            if (toast) {
                new bootstrap.Toast(toast, { delay: 3500 }).show();
            }
        });
    </script>
    <script>
    (function () {
        try {
        const mobileUsers = @json($mobileUsers);           // [{id, name}]
        const existing = @json($transitionsData);          // [{number, user_ids:[...]}]
        const builder = document.getElementById('transitionsBuilder');
        const payload = document.getElementById('transitionsPayload');

        function userOptions(selected) {
            return mobileUsers.map(u =>
                `<option value="${u.id}"${Number(selected) === u.id ? ' selected' : ''}>${u.name}</option>`
            ).join('');
        }

        function userColumn(userId) {
            const col = document.createElement('div');
            col.className = 'd-flex align-items-center gap-1 transition-user';
            col.innerHTML =
                `<select class="form-select form-select-sm t-user">${userOptions(userId)}</select>` +
                `<button type="button" class="btn btn-sm btn-outline-danger remove-user">&minus;</button>`;
            col.querySelector('.remove-user').addEventListener('click', () => { col.remove(); sync(); });
            col.querySelector('.t-user').addEventListener('change', sync);
            return col;
        }

        function transitionRow(number, userIds) {
            const row = document.createElement('div');
            row.className = 'border rounded p-2 mb-2 transition-row';
            row.innerHTML =
                `<div class="d-flex align-items-center gap-2 mb-2">` +
                `<label class="form-label mb-0 small">Number</label>` +
                `<input type="number" min="1" step="1" class="form-control form-control-sm t-number" style="width:100px" value="${number ?? ''}">` +
                `<button type="button" class="btn btn-sm btn-outline-primary add-user">+ user</button>` +
                `<button type="button" class="btn btn-sm btn-outline-danger remove-transition ms-auto">Remove lane</button>` +
                `</div><div class="d-flex flex-wrap gap-2 users"></div>`;
            const users = row.querySelector('.users');
            (userIds && userIds.length ? userIds : [mobileUsers[0]?.id]).forEach(id => users.appendChild(userColumn(id)));
            row.querySelector('.add-user').addEventListener('click', () => { users.appendChild(userColumn(mobileUsers[0]?.id)); sync(); });
            row.querySelector('.remove-transition').addEventListener('click', () => { row.remove(); sync(); });
            row.querySelector('.t-number').addEventListener('input', sync);
            return row;
        }

        function sync() {
            const rows = [...builder.querySelectorAll('.transition-row')].map(row => ({
                number: parseInt(row.querySelector('.t-number').value, 10),
                user_ids: [...row.querySelectorAll('.t-user')].map(s => parseInt(s.value, 10)),
            })).filter(r => Number.isInteger(r.number) && r.user_ids.length);
            payload.value = JSON.stringify(rows);
        }

        document.getElementById('addTransitionBtn').addEventListener('click', () => {
            builder.appendChild(transitionRow(null, [])); sync();
        });

        (existing.length ? existing : []).forEach(t => builder.appendChild(transitionRow(t.number, t.user_ids)));
        sync();
        } catch (e) {
            // Builder failed to initialise — suppress the hidden field so the server
            // receives no transitions_payload and syncTransitions() returns early (no-op),
            // leaving existing lanes untouched instead of interpreting "[]" as "wipe all".
            console.error('Transition builder init error:', e);
            document.getElementById('transitionsPayload')?.removeAttribute('name');
        }
    })();
    </script>
@endsection

@section('content')
    @if (session('status'))
        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;">
            <div class="toast bg-white border-0 shadow-lg rounded-3 overflow-hidden" id="filters-toast"
                 role="alert" aria-live="assertive" aria-atomic="true"
                 style="border-left: 4px solid #28c76f !important; min-width: 320px;">
                <div class="d-flex align-items-center p-3">
                    <span class="badge bg-label-success rounded-circle p-2 me-3 lh-1">
                        <i class="bx bx-check bx-sm"></i>
                    </span>
                    <div class="me-3">
                        <div class="fw-semibold text-body">Saved</div>
                        <small class="text-muted">{{ session('status') }}</small>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    @endif

    <h4 class="page-title">Filters</h4>
    <div class="row">
        <!-- FormValidation -->
        <div class="col-12">
            <div class="card">
                <h5 class="card-header">Set Crawler Filter</h5>
                <div class="card-body">

                    <form id="formValidationExamples" method="POST" action={{ route('updateFilters') }} class="row g-3
                    ">
                    @csrf

                    <div class="col-lg-7">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="formValidationNegativePrompt">Step 1 - Qualifier Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Qualifier Prompt"
                                   data-bs-content="Runs first, when the crawler saves a new project. Sent to OpenAI together with the project description to decide if the project matches your skip-criteria. If it matches, the project is marked Not Qualified and no bid is generated. If the AI call fails, the project is treated as not qualified (fail-closed)."></i>
                            </label>
                            <textarea class="form-control" id="formValidationNegativePrompt"
                                      name="formValidationNegativePrompt" rows="20">{{ $filter->negative_prompt }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="formValidationPrompt">Step 2 - Proposal Drafting Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Proposal Drafting Prompt"
                                   data-bs-content="Runs after the Qualifier Prompt gate passes. Used as the AI system message to write the bid cover letter for the project."></i>
                            </label>
                            <textarea class="form-control" id="formValidationPrompt" name="formValidationPrompt"
                                      rows="20">{{ $filter->prompt }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="formValidationSummaryPrompt">Step 3 - Response Allocation Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Response Allocation Prompt"
                                   data-bs-content="Runs when a project fails qualification. Sends the project itself (title and description) to OpenAI to produce a short project summary shown on the Not Qualified page and in bid details."></i>
                            </label>
                            <textarea class="form-control" id="formValidationSummaryPrompt"
                                      name="formValidationSummaryPrompt" rows="20">{{ $filter->summary_prompt }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="allocationPrompt">Allocation Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Allocation Prompt"
                                   data-bs-content="Sent to OpenAI on the first assignment of a new client thread. Describe which transition number to return for what kind of project. The AI returns a number that selects a transition; the thread is assigned to that transition's first user."></i>
                            </label>
                            <textarea class="form-control" id="allocationPrompt" name="allocation_prompt"
                                      rows="8">{{ $filter->allocation_prompt }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Transitions
                                <small class="text-muted">(escalation lanes)</small>
                            </label>
                            <div id="transitionsBuilder"></div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addTransitionBtn">
                                + Add transition
                            </button>
                            <input type="hidden" name="transitions_payload" id="transitionsPayload" value="[]">
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="mb-3">
                            <label class="form-label" for="formValidationCountries">Allowed Countries</label>
                            <select class="selectpicker w-100" id="formValidationCountries" data-style="btn-default"
                                    data-icon-base="bx" data-tick-icon="bx-check text-white"
                                    name="formValidationCountries[]"
                                    multiple @if (!$filter->usecountries) disabled @endif>
                                @foreach ($countries as $country)
                                    <option value="{{ $country->id }}" @if (in_array(
                                                $country->id,
                                                $filter->countries()->pluck('countries.id')->all())) selected @endif>
                                        {{ $country->country }} - {{ $country->language }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="formValidationCurrencies">Allowed Currencies
                                <small class="text-muted">(locked)</small></label>
                            <select class="selectpicker w-100" id="formValidationCurrencies" data-style="btn-default"
                                    data-icon-base="bx" data-tick-icon="bx-check text-white"
                                    name="formValidationCurrencies[]"
                                    multiple disabled>
                                @foreach ($currencies as $currency)
                                    <option value="{{ $currency->id }}" @if (in_array(
                                                $currency->id,
                                                $filter->currencies()->pluck('currencies.id')->all())) selected @endif>
                                        {{ $currency->currency_name }} - {{ $currency->curreny_symbol }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="formValidationMinHourlyRate">Min Hourly Project Rate</label>
                            <input type="number" class="form-control" name="formValidationMinHourlyRate"
                                   value="{{ $filter->min_hourly_amount }}"
                                   @if (!$filter->useminhour) disabled @endif />
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="formValidationMinFixedRate">Min Fixed Project Rate</label>
                            <input type="number" class="form-control" name="formValidationMinFixedRate"
                                   value="{{ $filter->min_fixed_amount }}"
                                   @if (!$filter->useminfix) disabled @endif />
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="formValidationEscalationMinutes">Chat Escalation Time
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Chat Escalation Time"
                                   data-bs-content="How long an unanswered client thread waits before it escalates to the next user on the escalation ladder."></i>
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" min="1" step="1"
                                       id="formValidationEscalationMinutes"
                                       name="formValidationEscalationMinutes"
                                       value="{{ (int) ($filter->escalation_minutes ?? 30) }}" />
                                <span class="input-group-text">minutes</span>
                            </div>
                        </div>

                        <div class="border rounded p-3">
                            <h6 class="fw-semibold mb-3">Control</h6>
                            <div class="mb-3">
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="formValidationCrawler" value="1"
                                           @if ($filter->crawler_on) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label fw-semibold">Enable Crawler</span>
                                </label>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-4">
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="useCountries"
                                           @if ($filter->usecountries) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label">Countries</span>
                                </label>
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="useminhour"
                                           @if ($filter->useminhour) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label">Min Hourly Cost</span>
                                </label>
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="useminfix"
                                           @if ($filter->useminfix) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label">Min Fixed Cost</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" name="submitButton" class="btn btn-primary px-4">
                            <i class="bx bx-save me-1"></i>Save Changes
                        </button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- /FormValidation -->
    </div>
@endsection
