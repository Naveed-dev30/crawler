<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFilterRequest;
use App\Http\Requests\UpdateFilterRequest;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Filter;
use App\Models\FreelancerProfile;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
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
        $mobileUsers = \App\Models\User::mobile()->get(['id', 'name']);
        $transitionsData = \App\Models\Transition::with('users')->get()->map(fn ($t) => [
            'number' => (int) $t->number,
            'user_ids' => $t->users->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        ]);

        $profiles = FreelancerProfile::orderBy('title')->get();

        return view('content.pages.filters', compact('filter', 'countries', 'currencies', 'mobileUsers', 'transitionsData', 'profiles'));
    }

    public function syncProfiles()
    {
        Artisan::call('profiles:sync');

        return back()->with('status', 'Profiles synced.');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create() {}

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreFilterRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show(Filter $filter)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return Response
     */
    public function edit(Filter $filter)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  UpdateFilterRequest  $request
     * @param  Filter  $filter
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

            $filter->allocation_prompt = $request->input('allocation_prompt', '') ?? '';

            $escalationMinutes = (int) $request->formValidationEscalationMinutes;
            $filter->escalation_minutes = $escalationMinutes >= 1
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

            $filter->usecountries = $request->useCountries == 'on' ? 1 : 0;
            $filter->useminfix = $request->useminfix == 'on' ? 1 : 0;
            $filter->useminhour = $request->useminhour == 'on' ? 1 : 0;

            $filter->save();

            $this->syncTransitions($request->input('transitions_payload'));

            return redirect('/filters')->with('status', 'Filters saved successfully.');
        } catch (Exception $exception) {
            Log::error("Something went wrong ar update fileters: {$exception->getMessage()}");
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy(Filter $filter)
    {
        //
    }

    private function syncTransitions(?string $payload): void
    {
        $rows = json_decode((string) $payload, true);
        if (! is_array($rows)) {
            return;
        }

        $mobileIds = \App\Models\User::mobile()->pluck('id')->all();

        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $mobileIds) {
            // Build the validated set of incoming lane numbers first.
            $seenNumbers = [];
            $validRows = [];
            foreach ($rows as $row) {
                $number = (int) ($row['number'] ?? 0);
                $userIds = array_values(array_unique(array_map('intval', $row['user_ids'] ?? [])));
                $userIds = array_values(array_filter($userIds, fn ($id) => in_array($id, $mobileIds, true)));

                if ($number < 1 || $userIds === [] || in_array($number, $seenNumbers, true)) {
                    continue;
                }
                $seenNumbers[] = $number;
                $validRows[] = ['number' => $number, 'user_ids' => $userIds];
            }

            // Remove only lanes whose number is no longer present; preserve existing ids
            // for lanes that still exist so in-flight thread pointers remain valid.
            \App\Models\Transition::whereNotIn('number', $seenNumbers)->delete();

            foreach ($validRows as ['number' => $number, 'user_ids' => $userIds]) {
                $transition = \App\Models\Transition::firstOrCreate(['number' => $number]);

                // Rewrite the user roster for this transition (positions may change).
                \App\Models\TransitionUser::where('transition_id', $transition->id)->delete();
                foreach ($userIds as $position => $userId) {
                    \App\Models\TransitionUser::create([
                        'transition_id' => $transition->id,
                        'user_id' => $userId,
                        'position' => $position,
                    ]);
                }
            }
        });
    }
}
