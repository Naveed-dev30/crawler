<?php

namespace App\Http\Controllers;

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
   * @param  \App\Http\Requests\StoreProposalRequest  $request
   * @return \Illuminate\Http\Response
   */
  public function store(StoreProposalRequest $request)
  {
    //
  }

  /**
   * Display the specified resource.
   *
   * @param  \App\Models\Proposal  $proposal
   * @return \Illuminate\Http\Response
   */
  public function show(Proposal $proposal)
  {
    //
  }

  /**
   * Show the form for editing the specified resource.
   *
   * @param  \App\Models\Proposal  $proposal
   * @return \Illuminate\Http\Response
   */
  public function edit(Proposal $proposal)
  {
    //
  }

  /**
   * Update the specified resource in storage.
   *
   * @param  \App\Http\Requests\UpdateProposalRequest  $request
   * @param  \App\Models\Proposal  $proposal
   * @return \Illuminate\Http\Response
   */
  public function update(UpdateProposalRequest $request, Proposal $proposal)
  {
    //
  }

  /**
   * Remove the specified resource from storage.
   *
   * @param  \App\Models\Proposal  $proposal
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
    $yesterday = $now->subDay()->unix();
    $accessAuthToken = 'U79CYvSQB4zLCWTy8JfQdWEMJaeJaq';

    $params = [
      'from_time' => $yesterday,
      'limit' => 10,
      'min_price' => $filter->min_fixed_amount,
      'min_hourly_rate' => $filter->min_hourly_amount,
      'sort_field' => 'time_updated',
      'full_description' => true,
      'compact' => true,
    ];

    $query = '';

    foreach ($params as $param => $value) {
      $query .= "{$param}={$value}&";
    }

    $query = rtrim($query, '&');

    $url = 'https://www.freelancer.com/api/projects/0.1/projects/all?projectSkills=1315?' . $query;

    $response = Http::withHeaders([
      'Freelancer-OAuth-V1' => $accessAuthToken,
    ])->get($url);

    if ($response->successful()) {
      $jsonResponse = $response->json();
      // return $jsonResponse;

      if ($jsonResponse['status'] === 'success') {
        $result = $jsonResponse['result'];

        $projects = $result['projects'];

        foreach ($projects as $project) {
          $currency = new Currency();
          $currency->currency_name = $project['currency']['code'];
          $currency->curreny_symbol = $project['currency']['sign'];

          $currencyExists = Currency::where('currency_name', $currency->currency_name)->exists();
          if (!$currencyExists) {
            $currency->save();
          }

          $country = new Country();
          $country->country = $project['currency']['country'];
          $country->language = $project['language'];

          $countryExists = Country::where('country', $country->country)->exists();

          if (!$countryExists) {
            $country->save();
          }

          if (!in_array($currency->currency_name, $filter->currencies->pluck('currency_name')->toArray())) {
            continue;
          }

          if (!in_array($country->country, $filter->countries->pluck('country')->toArray())) {
            continue;
          }

          $isNDA = $project['upgrades']['NDA'];
          $isSealed = $project['upgrades']['sealed'];

          if ($isNDA or $isSealed) {
            return;
          }

          $proposalExists = Proposal::where('project_id', $project['id'])->exists();

          if ($proposalExists) {
            return;
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

          $this->generateBid($proposal);
        }
      }
    }
  }

  public function generateBid(Proposal $proposal)
  {
    $bearer = 'Bearer ' . env('OPENAI_API_KEY');
    $url = 'https://api.openai.com/v1/chat/completions';

    $filter = Filter::find(1);

    if (!$filter->crawler_on) {
      return;
    }

    $prompt = $filter->prompt;

    $data = [
      'model' => 'gpt-3.5-turbo',
      'messages' => [
        [
          'role' => 'system',
          'content' => $prompt,
        ],
        [
          'role' => 'user',
          'content' => ' Description ' . $proposal->description,
        ],
      ],
    ];

    $response = Http::timeout(60)
      ->withHeaders(['Authorization' => $bearer])
      ->post($url, $data);

    $coverLetter = $response['choices'][0]['message']['content'];

    $bid = new Bid();
    $bid->proposal_id = $proposal->id;
    $bid->price = $proposal->max_budget * 0.9;
    $bid->cover_letter = $coverLetter;
    $bid->save();
  }
}
