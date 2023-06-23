<?php

namespace App\Http\Controllers;

use App\Models\Filter;
use App\Models\Country;
use App\Models\Keyword;
use App\Models\Currency;
use Illuminate\Http\Request;
use App\Http\Requests\StoreFilterRequest;
use App\Http\Requests\UpdateFilterRequest;
use App\Models\NegativeKeyword;

class FilterController extends Controller
{
  /**
   * Display a listing of the resource.
   *
   * @return \Illuminate\Http\Response
   */
  public function index()
  {
    $filter = Filter::find(1);
    $countries = Country::all();
    $currencies = Currency::all();
    $keywords = Keyword::all();
    $negKeywords = NegativeKeyword::all();
    return view('content.pages.filters', ['filter' => $filter, 'countries' => $countries, 'currencies' => $currencies, 'keywords' => $keywords, 'negKeywords' => $negKeywords]);
  }

  /**
   * Show the form for creating a new resource.
   *
   * @return \Illuminate\Http\Response
   */
  public function create()
  {
  }

  /**
   * Store a newly created resource in storage.
   *
   * @param  \App\Http\Requests\StoreFilterRequest  $request
   * @return \Illuminate\Http\Response
   */
  public function store(StoreFilterRequest $request)
  {
    //
  }

  /**
   * Display the specified resource.
   *
   * @param  \App\Models\Filter  $filter
   * @return \Illuminate\Http\Response
   */
  public function show(Filter $filter)
  {
    //
  }

  /**
   * Show the form for editing the specified resource.
   *
   * @param  \App\Models\Filter  $filter
   * @return \Illuminate\Http\Response
   */
  public function edit(Filter $filter)
  {
    //
  }

  /**
   * Update the specified resource in storage.
   *
   * @param  \App\Http\Requests\UpdateFilterRequest  $request
   * @param  \App\Models\Filter  $filter
   * @return \Illuminate\Http\Response
   */
  public function update(Request $request)
  {
    $countries = $request->formValidationCountries;
    $currencies = $request->formValidationCurrencies;
    $prompt = $request->formValidationPrompt;
    $crawlerOn = $request->formValidationCrawler;
    $minHourly = $request->formValidationMinHourlyRate;
    $minFixed = $request->formValidationMinFixedRate;
    $tags = $request->TagifyBasic;
    $negTags = $request->negativeKeywords;
    $selectedKeywords = $request->formValidationKeywords;



    $filter = Filter::find(1);

    if ($tags) {
      $existingKeywords = Keyword::pluck('name')->toArray();
      $tagsJson = json_decode($tags, true);
      $newKeywords = [];

      foreach ($tagsJson as $tag) {
        $newKeywords[] = $tag['value'];

        // Check if the keyword already exists in the database
        if (!in_array($tag['value'], $existingKeywords)) {
          // Create a new Keyword record and save it
          $keyword = new Keyword();
          $keyword->name = $tag['value'];
          $keyword->save();
        }
      }

      // Determine the keywords that need to be deleted
      $keywordsToDelete = array_diff($existingKeywords, $newKeywords);

      // Delete the keywords that are no longer present in the list
      Keyword::whereIn('name', $keywordsToDelete)->delete();
    }

    /// [Neg Tags]
    if ($negTags) {
      $existingNegKeywords = NegativeKeyword::pluck('name')->toArray();
      $negTagsJson = json_decode($negTags, true);
      $newNegKeywords = [];

      foreach ($negTagsJson as $negTag) {
        $newNegKeywords[] = $negTag['value'];

        // Check if the keyword already exists in the database
        if (!in_array($negTag['value'], $existingNegKeywords)) {
          // Create a new Keyword record and save it
          $negKeyword = new NegativeKeyword();
          $negKeyword->name = $negTag['value'];
          $negKeyword->save();
        }
      }

      // Determine the keywords that need to be deleted
      $negKeywordsToDelete = array_diff($existingNegKeywords, $newNegKeywords);

      // Delete the keywords that are no longer present in the list
      NegativeKeyword::whereIn('name', $negKeywordsToDelete)->delete();
    }

    if ($prompt) {
      $filter->prompt = $prompt;
    }

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

    if ($selectedKeywords) {
      $filter->keywords()->detach();
      foreach ($selectedKeywords as $keyword) {
        $filter->keywords()->attach($keyword);
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

    $filter->usekeywords = $request->usekeywords == "on" ? 1 : 0;
    $filter->usecountries = $request->useCountries == "on" ? 1 : 0;
    $filter->useminfix = $request->useminfix == "on" ? 1 : 0;
    $filter->useminhour = $request->useminhour == "on" ? 1 : 0;

    $filter->save();

    return redirect('/filters');
  }

  /**
   * Remove the specified resource from storage.
   *
   * @param  \App\Models\Filter  $filter
   * @return \Illuminate\Http\Response
   */
  public function destroy(Filter $filter)
  {
    //
  }
}
