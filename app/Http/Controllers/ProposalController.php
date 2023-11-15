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
use App\Models\NegativeKeyword;
use Illuminate\Support\Str;


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
        $accessAuthToken = env('FL_ACCESS');

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

        if ($filter->usekeywords) {
            $textQuery = '';

            $keywords = $filter->keywords()->pluck('name')->toArray();

            if ($keywords) {
                foreach ($keywords as $keyword) {
                    $textQuery = $textQuery . ' ' . $keyword;
                }
            }

            if ($textQuery) {
                $params['query'] = $textQuery;
            }
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

        $url = 'https://www.freelancer.com/api/projects/0.1/projects/active?' . $query;

        $response = Http::withHeaders([
            'Freelancer-OAuth-V1' => $accessAuthToken,
        ])->get($url);


        if ($response->successful()) {

            $jsonResponse = $response->json();

            $negativeKeywords = NegativeKeyword::pluck('name')->toArray();


            if ($jsonResponse['status'] === 'success') {

                $result = $jsonResponse['result'];


                $projects = $result['projects'];


                foreach ($projects as $project) {
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

                    $proposalExists = Proposal::where('project_id', $project['id'])->exists();

                    if ($proposalExists) {
                        continue;
                    }

                    $proposal = new Proposal();
                    /// [id]
                    $proposal->project_id = $project['id'];
                    /// [title]
                    $proposal->title = $project['title'];

                    if (Str::contains($proposal->title, $negativeKeywords)) {

                        continue;
                    }

                    /// [description]
                    $proposal->description = $project['description'];
                    if (Str::contains($proposal->description, $negativeKeywords)) {

                        continue;
                    }

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
                    /// [Project Owner]
                    $proposal->project_owner = $project['owner_id'];
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

                    $proposal->save();
                    $proposal->get();

                    OpenAIJob::dispatch($proposal);
                }
            }
        }
    }

    public function shouldNotProceed($project): bool
    {
        $response = Http::withHeaders([
            "freelancer-auth-v2" => "7032685;b3mJw8I8w8zk3scCNDcWNZP8Qa//CCbr00HBRcQRTEE=",
        ])->get("https://www.freelancer.com/api/support/0.1/agent_sessions/?agent_session_states%5B%5D=assigned&latest=true&source_type=project&sources%5B%5D={$project['id']}&support_types%5B%5D=recruiter&order_by=agent_session_create_time_dsc&webapp=1&compact=true&new_errors=true&new_pools=true");

        if ($response->ok()) {
            $jsonResponse = $response->json();
            if ($jsonResponse["result"] == null or $jsonResponse["result"]["agent_sessions"] == null or $jsonResponse["result"]["agent_sessions"]["agent_id"] == null) {
                return false;
            }

            return $jsonResponse["result"]["agent_sessions"]["agent_id"] === 954;
        }

        return false;
    }
}
