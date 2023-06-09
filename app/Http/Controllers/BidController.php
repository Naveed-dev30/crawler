<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\Filter;
use Illuminate\Http\Request;
use App\Http\Requests\StoreBidRequest;
use App\Http\Requests\UpdateBidRequest;
use Carbon\Carbon;
use DateTime;

class BidController extends Controller
{
  /**
   * Display a listing of the resource.
   *
   * @return \Illuminate\Http\Response
   */
  public function index()
  {
    $bids = Bid::latest()->paginate(100);
    return view('content.pages.home', ['bids' => $bids]);
  }

  public function stats()
  {
    $bidsStats = Bid::latestYear()->whereSeen()->groupByDate()->get();

    $firstDate = new DateTime();

    if ($bidsStats->toArray()) {
      $firstDate = $bidsStats[0]->date;
    }


    $calendar = [];

    $currentDate = Carbon::parse($firstDate)->startOfDay();
    $endDate = Carbon::now();

    while ($currentDate <= $endDate) {
      $calendar[$currentDate->format('Y-m-d')] = [
        'date' => $currentDate->format('Y-m-d'),
        'day' => $currentDate->format('d'),
        'month' => $currentDate->format('m'),
        'year' => $currentDate->format('Y'),
        'count' => 0,
      ];

      $currentDate->addDay();
    }


    foreach ($bidsStats as $bidStat) {
      $calendar[$bidStat->date]['count'] = $bidStat->count;
    }

    $values = [];

    foreach ($calendar as $key => $value) {
      array_push($values, $value);
    }

    return view('content.pages.stats', ['stats' => $values]);
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
   * @param  \App\Http\Requests\StoreBidRequest  $request
   * @return \Illuminate\Http\Response
   */
  public function store(StoreBidRequest $request)
  {
    //
  }

  /**
   * Display the specified resource.
   *
   * @param  \App\Models\Bid  $bid
   * @return \Illuminate\Http\Response
   */
  public function show(Bid $bid)
  {
    $bid->is_seen = true;
    $bid->save();
    return view('content.pages.filter_edit', ['bid' => $bid]);
  }

  /**
   * Show the form for editing the specified resource.
   *
   * @param  \App\Models\Bid  $bid
   * @return \Illuminate\Http\Response
   */
  public function edit(Bid $bid)
  {
  }

  /**
   * Update the specified resource in storage.
   *
   * @param  \App\Http\Requests\UpdateBidRequest  $request
   * @param  \App\Models\Bid  $bid
   * @return \Illuminate\Http\Response
   */
  public function update(UpdateBidRequest $request, Bid $bid)
  {
    //
  }

  /**
   * Remove the specified resource from storage.
   *
   * @param  \App\Models\Bid  $bid
   * @return \Illuminate\Http\Response
   */
  public function destroy(Bid $bid)
  {
    //
  }

  public function changeStatus(Request $request)
  {
    $status = $request->status;

    if (!$request->bidId) {
      return;
    }

    // if (!($status == 'completed' or $status == 'failed')) {
    //   return response()->json(['success' => false, 'message' => 'Invalid Status'], 400);
    // }

    $bidId = $request->bidId;

    $bid = Bid::find($bidId);

    if (!$bid) {
      return response()->json(['success' => false, 'message' => 'Bid with this ID not found.'], 400);
    }

    $bid->bid_status = $status;

    $bid->save();
    return response()->json(['success' => true, 'message' => 'Bid status has been updated successfully.'], 200);
  }

  public function getBid()
  {
    $latestBid = Bid::where('bid_status', 'pending')
      ->where('created_at', '>=', now()->subDay())
      ->latest()
      ->first();

    if (!$latestBid) {
      return 1 / 0;
    }

    $projectId = $latestBid->proposal->project_id;

    $data = [
      'id' => $latestBid->id,
      'bid_status' => $latestBid->bid_status,
      'price' => $latestBid->price,
      'cover_letter' => $latestBid->cover_letter,
      'project_id' => $projectId,
    ];

    return $data;
  }

  public function expireBids()
  {
    $notCompletedBids = Bid::where('bid_status', '!=', 'completed')->get();
    foreach ($notCompletedBids as $notCompletedBid) {
      $notCompletedBid->bid_status = 'expired';
      $notCompletedBid->save();
    }
    return redirect('/');
  }

  public function updateBidCheck(Request $request)
  {
    $bid = Bid::find($request->bid_id);

    $bid->check = $request->check;

    $bid->save();

    return redirect('/');
  }
}
