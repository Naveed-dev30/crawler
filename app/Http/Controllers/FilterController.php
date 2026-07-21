<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFilterRequest;
use App\Http\Requests\UpdateFilterRequest;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Filter;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Log;

class FilterController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $filter = Filter::find(1);
        $countries = Country::all();
        $currencies = Currency::all();

        return view('content.pages.filters', ['filter' => $filter, 'countries' => $countries, 'currencies' => $currencies]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreFilterRequest $request
     * @return Response
     */
    public function store(StoreFilterRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param Filter $filter
     * @return Response
     */
    public function show(Filter $filter)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Filter $filter
     * @return Response
     */
    public function edit(Filter $filter)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateFilterRequest $request
     * @param Filter $filter
     * @return Response
     */
    public function update(Request $request)
    {
        try {
            $countries = $request->formValidationCountries;
            $currencies = $request->formValidationCurrencies;
            $prompt = $request->formValidationPrompt;
            $negativePrompt = $request->formValidationNegativePrompt;
            $crawlerOn = $request->formValidationCrawler;
            $minHourly = $request->formValidationMinHourlyRate;
            $minFixed = $request->formValidationMinFixedRate;


            $filter = Filter::find(1);

            if ($prompt) {
                $filter->prompt = $prompt;
            }

            $filter->negative_prompt = $negativePrompt ?? '';

            $filter->summary_prompt = $request->formValidationSummaryPrompt ?? '';

            $filter->profile_match_prompt = $request->formValidationProfileMatchPrompt ?? '';

            $escalationMinutes = (int) $request->formValidationEscalationMinutes;
            $filter->escalation_minutes = in_array($escalationMinutes, [30, 120, 480, 1440], true)
                ? $escalationMinutes
                : 30;

            if ($crawlerOn) {
                $filter->crawler_on = $crawlerOn;
            } else {
                $filter->crawler_on = false;
            }

            if ($countries) {
                $filter->countries()->detach();
                foreach ($countries as $country) {
                    $filter->countries()->attach($country);
                }
            }

            if ($currencies) {
                $filter->currencies()->detach();
                foreach ($currencies as $currency) {
                    $filter->currencies()->attach($currency);
                }
            }

            if ($minFixed) {
                $filter->min_fixed_amount = $minFixed;
            }

            if ($minHourly) {
                $filter->min_hourly_amount = $minHourly;
            }

            $filter->usecountries = $request->useCountries == "on" ? 1 : 0;
            $filter->useminfix = $request->useminfix == "on" ? 1 : 0;
            $filter->useminhour = $request->useminhour == "on" ? 1 : 0;

            $filter->save();

            return redirect('/filters')->with('status', 'Filters saved successfully.');
        } catch (Exception $exception) {
            Log::error("Something went wrong ar update fileters: {$exception->getMessage()}");
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Filter $filter
     * @return Response
     */
    public function destroy(Filter $filter)
    {
        //
    }
}
