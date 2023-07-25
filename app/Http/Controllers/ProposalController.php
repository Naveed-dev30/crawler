<?php

namespace App\Http\Controllers;

use App\Jobs\BidNowJob;
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

        $now = Carbon::now();
        $yesterday = $now->subDays(6)->unix();
        $accessAuthToken = env('FL_ACCESS');

        $params = [
            'from_time' => $yesterday,
            'limit' => 10,
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
            $keywords = $filter->keywords->pluck('name')->implode(' ');
            if ($keywords) {
                $params['query'] = $keywords;
            }
        }

        if ($filter->usecountries) {
            $countryCodes = $filter->countries->pluck('language')->map(function ($code) {
                return strtolower($code);
            })->toArray();
            $params['countries'] = $countryCodes;
        }

        $url = 'https://www.freelancer.com/api/projects/0.1/projects/active?' . http_build_query($params);

        $response = Http::withHeaders([
            'Freelancer-OAuth-V1' => $accessAuthToken,
        ])->get($url);

        if (!$response->successful()) {
            return;
        }

        $jsonResponse = $response->json();
        if ($jsonResponse['status'] !== 'success') {
            return;
        }

        $projects = $jsonResponse['result']['projects'];
        $negativeKeywords = NegativeKeyword::pluck('name')->toArray();

        foreach ($projects as $project) {
            if ($this->isProjectValid($project, $negativeKeywords, $filter)) {
                $this->createProposalAndBid($project, $negativeKeywords);
            }
        }
    }

    protected function isProjectValid($project, $negativeKeywords, $filter)
    {
        $isNDA = $project['upgrades']['NDA'];
        $isSealed = $project['upgrades']['sealed'];

        if ($isNDA || $isSealed) {
            return false;
        }

        $title = $project['title'];
        $description = $project['description'];

        return !(Str::contains($title, $negativeKeywords) || Str::contains($description, $negativeKeywords))
            && $this->isBudgetValid($project, $filter);
    }

    protected function isBudgetValid($project, $filter)
    {
        $type = $project['type'];
        $minBudget = $project['budget']['minimum'];

        if ($type === 'fixed' && $filter->useminfix) {
            return $minBudget >= $filter->min_fixed_amount;
        }

        if ($type === 'hourly' && $filter->useminhour) {
            return $minBudget >= $filter->min_hourly_amount;
        }

        return true;
    }

    protected function createProposalAndBid($project, $negativeKeywords)
    {
        $proposalExists = Proposal::where('project_id', $project['id'])->exists();
        if ($proposalExists) {
            return;
        }

        $currency = new Currency([
            'currency_name' => $project['currency']['code'],
            'curreny_symbol' => $project['currency']['sign'],
        ]);

        $country = new Country([
            'country' => $project['currency']['country'],
            'language' => $project['language'],
        ]);

        $proposal = new Proposal([
            'project_id' => $project['id'],
            'title' => $project['title'],
            'description' => $project['description'],
            'seo_url' => $project['seo_url'],
            'type' => $project['type'],
            'min_budget' => $project['budget']['minimum'],
            'max_budget' => $project['budget']['maximum'] ?? $project['budget']['minimum'],
            'project_owner' => $project['owner_id'],
            'language' => $project['language'],
            'currency_symbol' => $currency->curreny_symbol,
            'currency_name' => $currency->currency_name,
            'project_added_time' => $project['time_submitted'],
            'country' => $country->country,
        ]);

        if (!$proposal->isValid($negativeKeywords)) {
            return;
        }

        $proposal->save();

        $this->dispatchBidNowJob($proposal);
    }

    protected function dispatchBidNowJob($proposal)
    {
        BidNowJob::dispatch($proposal);
    }
}
