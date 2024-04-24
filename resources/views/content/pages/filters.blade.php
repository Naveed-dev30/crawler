@extends('layouts.layoutMaster')


@section('title', 'Filters')


@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/select2/select2.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/flatpickr/flatpickr.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/typeahead-js/typeahead.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/tagify/tagify.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/formvalidation/dist/css/formValidation.min.css') }}"/>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/moment/moment.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/flatpickr/flatpickr.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/typeahead-js/typeahead.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/tagify/tagify.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/FormValidation.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/plugins/Bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/plugins/AutoFocus.min.js') }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset('assets/js/form-validation.js') }}"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const tagifyBasicEl = document.querySelector("#TagifyBasic");
            new Tagify(tagifyBasicEl);

            const tagifyBasicNegativeKeywords = document.querySelector("#tagifyNegativeKeywords");
            new Tagify(tagifyBasicNegativeKeywords);
        });
    </script>
@endsection

@php
    $tags = '';
    $negTags = '';
@endphp

@foreach ($keywords as $keyword)
    @php
        $tags = $tags . ',' . $keyword->name;
    @endphp
@endforeach

@foreach ($negKeywords as $negKeyword)
    @php
        $negTags = $negTags . ',' . $negKeyword->name;
    @endphp
@endforeach

@section('content')
    <h4 class="py-3 breadcrumb-wrapper mb-4">
        <span class="fw-light">Filters</span>
    </h4>
    <div class="row">
        <!-- FormValidation -->
        <div class="col-12">
            <div class="card">
                <h5 class="card-header">Set Crawler Filter</h5>
                <div class="card-body">

                    <form id="formValidationExamples" method="POST" action={{ route('updateFilters') }} class="row g-3
                    ">
                    @csrf
                    <!-- Personal Info -->
                    <div class="col-12">
                        <h6 class="mt-2 fw-normal">1. Filter Data</h6>
                        <hr class="mt-0"/>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationCountries">Countries</label>
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
                        <label class="form-label" for="formValidationCurrencies">Currencies</label>
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
                        <label class="form-label" for="formValidationMinHourlyRate">Min Hourly Rate</label>
                        <input type="number" class="form-control" name="formValidationMinHourlyRate"
                               value="{{ $filter->min_hourly_amount }}" rows="3"
                               @if (!$filter->useminhour) disabled @endif></input>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationMinFixedRate">Min Fixed Rate</label>
                        <input type="number" class="form-control" name="formValidationMinFixedRate" rows="3"
                               value="{{ $filter->min_fixed_amount }}"
                               @if (!$filter->useminfix) disabled @endif />
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationPrompt">Prompt</label>
                        <textarea class="form-control" id="formValidationPrompt" name="formValidationPrompt"
                                  rows="3">{{ $filter->prompt }}</textarea>
                    </div>

                    <span class="col-md-6">
                            <div class="mb-3">
                                <label for="tagifyNegativeKeywords" class="form-label">Negative
                                    Keywords</label>
                                <input id="tagifyNegativeKeywords" class="form-control" name="negativeKeywords"
                                       value={{ $negTags }} />
                            </div>
                        </span>

                    <span class="col-md-6">
                            <div class="mb-3">
                                <label for="TagifyBasic" class="form-label">Keywords</label>
                                <input id="TagifyBasic" class="form-control" name="TagifyBasic"
                                       @if (!$filter->usekeywords) disabled @endif value={{ $tags }} />
                            </div>
                        </span>
                    <span class="col-md-6"></span>
                    <div class="col-md-6">
                        <label class="form-label" for="formValidationKeywords">Select</label>
                        <select class="selectpicker w-100" id="formValidationKeywords" data-style="btn-default"
                                data-icon-base="bx" data-tick-icon="bx-check text-white" name="formValidationKeywords[]"
                                multiple @if (!$filter->usekeywords) disabled @endif>
                            @foreach ($keywords as $keyword)
                                <option value="{{ $keyword->id }}" @if (in_array(
                                            $keyword->id,
                                            $filter->keywords()->pluck('keywords.id')->all())) selected @endif>
                                    {{ $keyword->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="formValidationCheckbox"
                               name="formValidationCrawler" value="1"
                               @if ($filter->crawler_on) checked @endif />
                        <label class="form-check-label">Crawler Enabled</label>
                    </div>

                    <div class="row">
                        {{-- Use Keywords --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="usekeywords"
                                   @if ($filter->usekeywords) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Keywords</span>
                        </label>

                        {{-- Use Countries --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="useCountries"
                                   @if ($filter->usecountries) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Countries</span>
                        </label>

                        {{-- Use Min Fixed --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="useminfix"
                                   @if ($filter->useminfix) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Min Fixed Cost</span>
                        </label>

                        {{-- Use Min Hourly --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="useminhour"
                                   @if ($filter->useminhour) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Min Hourly Cost</span>
                        </label>
                    </div>

                    <div class="col-12">
                        <button type="Save" name="submitButton" class="btn btn-primary">Submit</button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- /FormValidation -->
    </div>
@endsection
