<?php

namespace App\Http\Controllers;

use App\Models\Filter;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Http\Request;
use App\Http\Requests\StoreFilterRequest;
use App\Http\Requests\UpdateFilterRequest;

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
    return view('content.pages.filters', ['filter' => $filter, 'countries' => $countries, 'currencies' => $currencies]);
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

    $filter = Filter::find(1);

    if($prompt){
      $filter->prompt = $prompt;
    }

    if($crawlerOn){
      $filter->crawler_on = $crawlerOn;
    }else{
      $filter->crawler_on = false;
    }


    if($countries){
      $filter->countries()->detach();
      foreach($countries as $country){
          $filter->countries()->attach($country);
      }
    }


    if($currencies){
      $filter->currencies()->detach();
      foreach($currencies as $currency){
          $filter->currencies()->attach($currency);
      }
    }

    $filter->save();

    return redirect('/');
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
