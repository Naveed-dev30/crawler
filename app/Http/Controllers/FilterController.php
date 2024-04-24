<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFilterRequest;
use App\Http\Requests\UpdateFilterRequest;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Filter;
use App\Models\Keyword;
use App\Models\NegativeKeyword;
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
        $keywords = Keyword::all();
        $negKeywords = NegativeKeyword::all();
        $tagsValue = "";

        foreach ($keywords as $keyword) {
            $tagsValue = "$tagsValue,$keyword->name";
        }

        dd($tagsValue);

        return view('content.pages.filters', ['filter' => $filter, 'countries' => $countries, 'currencies' => $currencies, 'keywords' => $keywords, 'negKeywords' => $negKeywords, 'tagsValue' => ',flutter,firebase,Iphone,android,ios,app,mobile,dart,website,landing,wordpress,css,html,mysql,web,woocommerce,laravel,backend,frontend,java,development,kotlin,Ecommerce,e-commerce,application,developer,saas,ChatGPT,hybrid,marketing,software,custom,create,content,writer,crm,cms,maintenance,product,products,multiplatform,payment,gateway,integrations,blockchain,ui/ux,ui,ux,rive,restaurant,piza,shop,elementor,logo,branding,brand,php,Gutenberg,Yoast']);
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
            dd($request);

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
                dd($tags);
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
