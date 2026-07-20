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
        });
    </script>
@endsection

@section('content')
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

                    <div class="col-12">
                        <h6 class="mt-2 fw-normal">1. Project Criteria</h6>
                        <hr class="mt-0"/>
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="formValidationCountries">Countries</label>
                            <label class="switch switch-success switch-sm mb-0">
                                <input type="checkbox" class="switch-input" name="useCountries"
                                       @if ($filter->usecountries) checked @endif />
                                <span class="switch-toggle-slider">
                                    <span class="switch-on"><i class="bx bx-check"></i></span>
                                    <span class="switch-off"><i class="bx bx-x"></i></span>
                                </span>
                            </label>
                        </div>
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

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationCurrencies">Currencies
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

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="formValidationMinHourlyRate">Min Hourly Rate</label>
                            <label class="switch switch-success switch-sm mb-0">
                                <input type="checkbox" class="switch-input" name="useminhour"
                                       @if ($filter->useminhour) checked @endif />
                                <span class="switch-toggle-slider">
                                    <span class="switch-on"><i class="bx bx-check"></i></span>
                                    <span class="switch-off"><i class="bx bx-x"></i></span>
                                </span>
                            </label>
                        </div>
                        <input type="number" class="form-control" name="formValidationMinHourlyRate"
                               value="{{ $filter->min_hourly_amount }}"
                               @if (!$filter->useminhour) disabled @endif />
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="formValidationMinFixedRate">Min Fixed Rate</label>
                            <label class="switch switch-success switch-sm mb-0">
                                <input type="checkbox" class="switch-input" name="useminfix"
                                       @if ($filter->useminfix) checked @endif />
                                <span class="switch-toggle-slider">
                                    <span class="switch-on"><i class="bx bx-check"></i></span>
                                    <span class="switch-off"><i class="bx bx-x"></i></span>
                                </span>
                            </label>
                        </div>
                        <input type="number" class="form-control" name="formValidationMinFixedRate"
                               value="{{ $filter->min_fixed_amount }}"
                               @if (!$filter->useminfix) disabled @endif />
                    </div>

                    <div class="col-12">
                        <h6 class="mt-4 fw-normal">2. AI Prompts</h6>
                        <hr class="mt-0"/>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="formValidationNegativePrompt">Qualifier Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Qualifier Prompt"
                               data-bs-content="Runs first, when the crawler saves a new project. Sent to OpenAI together with the project description to decide if the project matches your skip-criteria. If it matches, the project is marked Not Qualified and no bid is generated. If the AI call fails, the project is treated as not qualified (fail-closed)."></i>
                        </label>
                        <textarea class="form-control" id="formValidationNegativePrompt"
                                  name="formValidationNegativePrompt" rows="4">{{ $filter->negative_prompt }}</textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="formValidationPrompt">Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Prompt"
                               data-bs-content="Runs after the Qualifier Prompt gate passes. Used as the AI system message to write the bid cover letter for the project."></i>
                        </label>
                        <textarea class="form-control" id="formValidationPrompt" name="formValidationPrompt"
                                  rows="4">{{ $filter->prompt }}</textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="formValidationSummaryPrompt">Summary Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Summary Prompt"
                               data-bs-content="Runs only when a project fails qualification and a rejection reason was recorded. Rewrites the raw reason into a short summary shown on the Not Qualified page and in bid details."></i>
                        </label>
                        <textarea class="form-control" id="formValidationSummaryPrompt"
                                  name="formValidationSummaryPrompt" rows="4">{{ $filter->summary_prompt }}</textarea>
                    </div>

                    <div class="col-12">
                        <hr class="mb-0"/>
                    </div>

                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <label class="switch switch-success mb-0">
                            <input type="checkbox" class="switch-input" name="formValidationCrawler" value="1"
                                   @if ($filter->crawler_on) checked @endif />
                            <span class="switch-toggle-slider">
                                <span class="switch-on"><i class="bx bx-check"></i></span>
                                <span class="switch-off"><i class="bx bx-x"></i></span>
                            </span>
                            <span class="switch-label">Crawler Enabled</span>
                        </label>
                        <button type="Save" name="submitButton" class="btn btn-primary">Submit</button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- /FormValidation -->
    </div>
@endsection
