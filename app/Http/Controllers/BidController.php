<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\Filter;
use Illuminate\Http\Request;
use App\Http\Requests\StoreBidRequest;
use App\Http\Requests\UpdateBidRequest;

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
    $filter = Filter::find(1);

    $latestBid = Bid::where('bid_status', 'pending')
      ->where('created_at', '>=', now()->subDay())
      // ->whereHas('proposal', function ($query) use ($filter) {
      //   $query->whereIn('country', $filter->countries->pluck('language')->toArray());
      // })
      ->latest()
      ->first();

    if (!$latestBid) {
      return response()->json(
        [
          'bid_status' => '',
        ],
        200
      );
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
}
