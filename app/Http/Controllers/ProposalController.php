<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;
use App\Jobs\OpenAIJob;
use App\Models\Bid;
use App\Models\BidInsight;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Filter;
use App\Models\Proposal;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class ProposalController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index() {}

    /** Slide-over detail for a not-qualified proposal (AJAX HTML fragment). */
    public function nqDetail(Proposal $proposal)
    {
        return view('_partials.not-qualified-detail', ['proposal' => $proposal])->render();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreProposalRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show(Proposal $proposal)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return Response
     */
    public function edit(Proposal $proposal)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(UpdateProposalRequest $request, Proposal $proposal)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy(Proposal $proposal)
    {
        //
    }

    public function getProposals()
    {
        $filter = Filter::find(1);

        if (! $filter->crawler_on) {
            return;
        }

        $yesterday = Carbon::now()->subHours(1);
        $accessAuthToken = config('variables.flKey');

        $params = [
            'from_time' => $yesterday,
            'limit' => 200,
            'sort_field' => 'time_updated',
            'full_description' => true,
            // Without job_details the projects/active payload returns job IDs
            // only (no names), so proposal skills come back empty.
            'job_details' => true,
            // Client ("About the client") info: adds a result.users map (and
            // owner_id on each project) with the owner's profile, employer
            // reputation (rating/reviews/completed), country and status. Note:
            // no `compact` — it strips owner_id, which we need to index users.
            // owner_info=true attaches the client (project owner) user object
            // directly on each project as `owner_info` — the reliable source,
            // since projects/active hides owner_id + the users map.
            'owner_info' => true,
            'user_details' => true,
            'user_avatar' => true,
            'user_display_info' => true,
            'user_employer_reputation' => true,
            'user_reputation' => true,
            'user_country_details' => true,
            'user_status' => true,
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

        $url = rtrim(config('variables.flBase'), '/').'/api/projects/0.1/projects/active?'.$query;

        $response = Http::timeout(30)->withHeaders([
            'Freelancer-OAuth-V1' => $accessAuthToken,
        ])->get($url);

        if ($response->successful()) {

            $jsonResponse = $response->json();

            if ($jsonResponse['status'] === 'success') {
                $result = $jsonResponse['result'];

                $projects = $result['projects'];

                // user_details=true returns a sibling map keyed by user id.
                $users = $result['users'] ?? [];

                foreach ($projects as $project) {
                    try {
                        if ($this->shouldNotProceed($project)) {
                            continue;
                        }

                        $currency = new Currency;
                        $currency->currency_name = $project['currency']['code'];
                        $currency->curreny_symbol = $project['currency']['sign'];

                        $country = new Country;
                        $country->country = $project['currency']['country'];
                        $country->language = $project['language'];

                        $isNDA = $project['upgrades']['NDA'];
                        $isSealed = $project['upgrades']['sealed'];

                        if ($isNDA or $isSealed) {
                            continue;
                        }

                        // Defense-in-depth: even if the API returns it, never bid on a
                        // project whose country is not in the selected whitelist.
                        if (! $this->countryAllowed($filter, $project['currency']['country'] ?? null)) {
                            continue;
                        }

                        $proposalExists = Proposal::where('project_id', $project['id'])->exists();

                        if ($proposalExists) {
                            continue;
                        }

                        $proposal = new Proposal;
                        // / [id]
                        $proposal->project_id = $project['id'];
                        // / [title]
                        $proposal->title = $project['title'];

                        // / [description]
                        $proposal->description = $project['description'];

                        // / [seo url]
                        $proposal->seo_url = $project['seo_url'];
                        // / [type]
                        $proposal->type = $project['type'];
                        // / [Min Cost]
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

                        // / [Max Cost]
                        $proposal->max_budget = $project['budget']['maximum'] ?? $project['budget']['minimum'];
                        // / [Project Owner] (absent from compact API responses; column is nullable and unused downstream)
                        $proposal->project_owner = $project['owner_id'] ?? null;
                        // / [Language]
                        $proposal->language = $project['language'];
                        // /[Currency Symbol]
                        $proposal->currency_symbol = $currency->curreny_symbol;
                        // / [currency_name]
                        $proposal->currency_name = $currency->currency_name;
                        // / [Added Time]
                        $proposal->project_added_time = $project['time_submitted'];
                        // / [Country]
                        $proposal->country = $country->country;
                        // / [Exchange rate → USD]
                        $proposal->exchange_rate = $project['currency']['exchange_rate'] ?? 1;
                        // / [Skills]
                        $proposal->skills = collect($project['jobs'] ?? [])->pluck('name')->values()->all();

                        $proposal->save();
                        $proposal->get();

                        // Capture "About the client" info so the mobile thread
                        // detail has it even when no extension ingest ran for
                        // this project. Creates the bid_insights row when absent.
                        $this->storeClientInsight($project, $users);

                        OpenAIJob::dispatch($proposal);
                    } catch (\Throwable $e) {
                        \Log::warning('Skipping project '.($project['id'] ?? '?').': '.$e->getMessage());

                        continue;
                    }
                }
            }
        }

    }

    /**
     * Upsert the client ("About the client") info for a project into
     * bid_insights, keyed by project_id. Sourced from the projects/active
     * user_details + employer-reputation projection. Only non-null fields are
     * written so a later extension ingest (or an earlier one) is never
     * clobbered with blanks. Creates the row when absent — otherwise the
     * mobile thread's client block stays null for crawler-only projects.
     */
    private function storeClientInsight(array $project, array $users): void
    {
        // Prefer the owner object attached directly by owner_info=true; fall
        // back to the users map (owner_id) when only that projection is present.
        $owner = $project['owner_info'] ?? null;
        if (! is_array($owner)) {
            $ownerId = $project['owner_id'] ?? null;
            $owner = $ownerId !== null ? ($users[$ownerId] ?? $users[(string) $ownerId] ?? null) : null;
        }

        if (! is_array($owner)) {
            return;
        }

        $history = $owner['employer_reputation']['entire_history'] ?? [];

        // Prefer the project's own client_engagement object; fall back to what
        // the employer reputation / invited list gives us.
        $engagement = is_array($project['client_engagement'] ?? null)
            ? $project['client_engagement']
            : array_filter([
                'completed' => $history['complete'] ?? null,
                'invited' => isset($project['invited_freelancers']) ? count($project['invited_freelancers']) : null,
            ], fn ($v) => $v !== null);

        $attributes = array_filter([
            'client_name' => $owner['display_name'] ?? $owner['public_name'] ?? $owner['username'] ?? null,
            'client_avatar' => $owner['avatar_large_cdn'] ?? $owner['avatar_cdn'] ?? $owner['avatar'] ?? null,
            'client_country' => $owner['location']['country']['name'] ?? null,
            'client_rating' => $history['overall'] ?? null,
            'client_reviews' => $history['reviews'] ?? null,
            'client_engagement' => $engagement !== [] ? $engagement : null,
        ], fn ($v) => $v !== null);

        if ($attributes === []) {
            return;
        }

        $attributes['last_scraped_at'] = now();

        BidInsight::updateOrCreate(['project_id' => $project['id']], $attributes);
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
        if (! $filter->usecountries) {
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
            'freelancer-auth-v2' => '7032685;b3mJw8I8w8zk3scCNDcWNZP8Qa//CCbr00HBRcQRTEE=',
        ])->get(rtrim(config('variables.flBase'), '/')."/api/support/0.1/agent_sessions/?agent_session_states%5B%5D=assigned&latest=true&source_type=project&sources%5B%5D={$project['id']}&support_types%5B%5D=recruiter&order_by=agent_session_create_time_dsc&webapp=1&compact=true&new_errors=true&new_pools=true");

        if ($response->ok()) {
            $jsonResponse = $response->json();
            if ($jsonResponse['result'] == null or $jsonResponse['result']['agent_sessions'] == null) {
                return false;
            }

            foreach ($jsonResponse['result']['agent_sessions'] as $sessionResult) {
                if ($sessionResult['agent_id'] === 954) {
                    return true;
                }
            }
        }

        return false;
    }
}
