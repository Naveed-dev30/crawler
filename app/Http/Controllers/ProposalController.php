<?php

namespace App\Http\Controllers;

use App\Jobs\BidNowJob;
use App\Jobs\OpenAIJob;
use App\Models\Bid;
use App\Models\Country;
use Carbon\Carbon;
use App\Models\Filter;
use App\Models\Currency;
use App\Models\Proposal;
use Illuminate\Support\Facades\Http;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;


class ProposalController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
    }

    /** Slide-over detail for a not-qualified proposal (AJAX HTML fragment). */
    public function nqDetail(Proposal $proposal)
    {
        return view('_partials.not-qualified-detail', ['proposal' => $proposal])->render();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \App\Http\Requests\StoreProposalRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreProposalRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Proposal $proposal
     * @return \Illuminate\Http\Response
     */
    public function show(Proposal $proposal)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Models\Proposal $proposal
     * @return \Illuminate\Http\Response
     */
    public function edit(Proposal $proposal)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \App\Http\Requests\UpdateProposalRequest $request
     * @param \App\Models\Proposal $proposal
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateProposalRequest $request, Proposal $proposal)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Proposal $proposal
     * @return \Illuminate\Http\Response
     */
    public function destroy(Proposal $proposal)
    {
        //
    }

    public function getProposals()
    {
        $filter = Filter::find(1);

        if (!$filter->crawler_on) {
            return;
        }

        $yesterday = Carbon::now()->subHours(1);
        $accessAuthToken = config('variables.flKey');

        $params = [
            'from_time' => $yesterday,
            'limit' => 200,
            'sort_field' => 'time_updated',
            'full_description' => true,
            'compact' => true,
        ];

        if ($filter->useminfix) {
            $params['min_price'] = $filter->min_fixed_amount;
        }

        if ($filter->useminhour) {
            $params['min_hourly_rate'] = $filter->min_hourly_amount;
        }

        $query = '';

        foreach ($params as $param => $value) {
            $query .= "{$param}={$value}&";
        }

        if ($filter->usecountries) {
            foreach ($filter->countries as $country) {
                $code = strtolower($country->language);
                $query .= "countries[]={$code}&";
            }
        }

        $query = rtrim($query, '&');

        $url = rtrim(config('variables.flBase'), '/') . '/api/projects/0.1/projects/active?' . $query;

        $response = Http::timeout(30)->withHeaders([
            'Freelancer-OAuth-V1' => $accessAuthToken,
        ])->get($url);

        if ($response->successful()) {

            $jsonResponse = $response->json();

            if ($jsonResponse['status'] === 'success') {
                $result = $jsonResponse['result'];


                $projects = $result['projects'];

                foreach ($projects as $project) {
                    try {
                    if ($this->shouldNotProceed($project)) {
                        continue;
                    }

                    $currency = new Currency();
                    $currency->currency_name = $project['currency']['code'];
                    $currency->curreny_symbol = $project['currency']['sign'];

                    $country = new Country();
                    $country->country = $project['currency']['country'];
                    $country->language = $project['language'];


                    $isNDA = $project['upgrades']['NDA'];
                    $isSealed = $project['upgrades']['sealed'];


                    if ($isNDA or $isSealed) {
                        continue;
                    }

                    // Defense-in-depth: even if the API returns it, never bid on a
                    // project whose country is not in the selected whitelist.
                    if (!$this->countryAllowed($filter, $project['currency']['country'] ?? null)) {
                        continue;
                    }

                    $proposalExists = Proposal::where('project_id', $project['id'])->exists();

                    if ($proposalExists) {
                        continue;
                    }

                    $proposal = new Proposal();
                    /// [id]
                    $proposal->project_id = $project['id'];
                    /// [title]
                    $proposal->title = $project['title'];

                    /// [description]
                    $proposal->description = $project['description'];

                    /// [seo url]
                    $proposal->seo_url = $project['seo_url'];
                    /// [type]
                    $proposal->type = $project['type'];
                    /// [Min Cost]
                    $proposal->min_budget = $project['budget']['minimum'];

                    if ($proposal->type == 'fixed') {
                        if ($filter->useminfix) {
                            if ($proposal->min_budget < $filter->min_fixed_amount) {
                                continue;
                            }
                        }
                    } else {
                        if ($filter->useminhour) {
                            if ($proposal->min_budget < $filter->min_hourly_amount) {
                                continue;
                            }
                        }
                    }

                    /// [Max Cost]
                    $proposal->max_budget = $project['budget']['maximum'] ?? $project['budget']['minimum'];
                    /// [Project Owner] (absent from compact API responses; column is nullable and unused downstream)
                    $proposal->project_owner = $project['owner_id'] ?? null;
                    /// [Language]
                    $proposal->language = $project['language'];
                    ///[Currency Symbol]
                    $proposal->currency_symbol = $currency->curreny_symbol;
                    /// [currency_name]
                    $proposal->currency_name = $currency->currency_name;
                    /// [Added Time]
                    $proposal->project_added_time = $project['time_submitted'];
                    /// [Country]
                    $proposal->country = $country->country;
                    /// [Exchange rate → USD]
                    $proposal->exchange_rate = $project['currency']['exchange_rate'] ?? 1;
                    /// [Skills]
                    $proposal->skills = collect($project['jobs'] ?? [])->pluck('name')->values()->all();

                    $proposal->save();
                    $proposal->get();

                    OpenAIJob::dispatch($proposal);
                    } catch (\Throwable $e) {
                        \Log::warning("Skipping project " . ($project['id'] ?? '?') . ": " . $e->getMessage());
                        continue;
                    }
                }
            }
        }

    }

    /**
     * Whether a project's currency-country is allowed by the filter's country whitelist.
     *
     * Freelancer reports the currency's country as a currency-region code
     * (USD->US, GBP->UK, EUR->EU, INR->IN...), while the filter whitelist stores
     * ISO country codes (United Kingdom = GB, euro countries individually). This
     * normalizes the few that differ so genuine countries (India) are blocked while
     * wanted ones (UK, euro) are kept.
     */
    public function countryAllowed(Filter $filter, ?string $currencyCountry): bool
    {
        if (!$filter->usecountries) {
            return true;
        }

        $code = strtoupper(trim((string) $currencyCountry));
        if ($code === '') {
            return true; // unknown country -> don't block
        }

        $allowed = $filter->countries
            ->pluck('language')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->all();

        // Currency-region codes that differ from the ISO code used in the whitelist.
        $normalized = ['UK' => 'GB'][$code] ?? $code;

        // Euro currency reports 'EU' (no single country). Accept it when the user
        // whitelisted any eurozone country.
        if ($normalized === 'EU') {
            $eurozone = ['DE', 'FR', 'IT', 'ES', 'NL', 'IE', 'AT', 'BE', 'PT', 'FI',
                         'GR', 'LU', 'SK', 'SI', 'EE', 'LV', 'LT', 'CY', 'MT'];
            return count(array_intersect($allowed, $eurozone)) > 0;
        }

        return in_array($normalized, $allowed, true);
    }

    public function shouldNotProceed($project): bool
    {
        $response = Http::timeout(30)->withHeaders([
            "freelancer-auth-v2" => "7032685;b3mJw8I8w8zk3scCNDcWNZP8Qa//CCbr00HBRcQRTEE=",
        ])->get(rtrim(config('variables.flBase'), '/') . "/api/support/0.1/agent_sessions/?agent_session_states%5B%5D=assigned&latest=true&source_type=project&sources%5B%5D={$project['id']}&support_types%5B%5D=recruiter&order_by=agent_session_create_time_dsc&webapp=1&compact=true&new_errors=true&new_pools=true");

        if ($response->ok()) {
            $jsonResponse = $response->json();
            if ($jsonResponse["result"] == null or $jsonResponse["result"]["agent_sessions"] == null) {
                return false;
            }

            foreach ($jsonResponse["result"]["agent_sessions"] as $sessionResult) {
                if ($sessionResult["agent_id"] === 954) {
                    return true;
                }
            }
        }
        return false;
    }
}
