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
    return view('content.pages.home');
  }

  private function filteredBidQuery(Request $request)
  {
    $query = Bid::query()
      ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
      ->select('bids.*');

    if ($request->filled('from')) {
      $query->where('bids.created_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
    }
    if ($request->filled('to')) {
      $query->where('bids.created_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
    }
    if (is_numeric($request->query('min'))) {
      $query->where('bids.price', '>=', (float) $request->query('min'));
    }
    if (is_numeric($request->query('max'))) {
      $query->where('bids.price', '<=', (float) $request->query('max'));
    }
    if (in_array($request->query('type'), ['fixed', 'hourly'], true)) {
      $query->where('proposals.type', $request->query('type'));
    }
    if ($request->filled('q')) {
      $q = $request->query('q');
      $query->where(function ($sub) use ($q) {
        $sub->where('proposals.title', 'like', "%{$q}%")
          ->orWhere('proposals.project_id', 'like', "%{$q}%");
      });
    }

    return $query;
  }

  public function data(Request $request)
  {
    $placed = ['pending', 'completed'];
    $failed = ['failed', 'expired'];

    $base = $this->filteredBidQuery($request);

    $cards = [
      'total'  => (clone $base)->count(),
      'placed' => (clone $base)->whereIn('bids.bid_status', $placed)->count(),
      'failed' => (clone $base)->whereIn('bids.bid_status', $failed)->count(),
    ];

    $statusCounts = (clone $base)
      ->whereNotIn('bids.bid_status', ['Project Missing', 'Skill Missing', 'Handle'])
      ->select('bids.bid_status as s')
      ->selectRaw('COUNT(*) as c')
      ->groupBy('bids.bid_status')
      ->orderByDesc('c')
      ->pluck('c', 's');

    $tab = in_array($request->query('tab'), ['failed', 'completed'], true)
      ? $request->query('tab')
      : 'placed';
    $statuses = match ($tab) {
      'failed' => $failed,
      'completed' => ['completed'],
      default => $placed,
    };
    $isCompleted = $tab === 'completed';

    $bids = (clone $base)
      ->whereIn('bids.bid_status', $statuses)
      ->with('proposal')
      ->latest('bids.created_at')
      ->paginate(100)
      ->withQueryString();

    $rowsHtml = '';
    foreach ($bids as $bid) {
      $rowsHtml .= view('_partials.bid-row', ['bid' => $bid, 'completed' => $isCompleted])->render();
    }
    if ($bids->isEmpty()) {
      $colspan = $isCompleted ? 9 : 7;
      $rowsHtml = '<tr><td colspan="' . $colspan . '" class="text-center text-muted py-4">No bids match these filters.</td></tr>';
    }

    return response()->json([
      'cards' => $cards,
      'statusCounts' => $statusCounts,
      'rowsHtml' => $rowsHtml,
      'paginationHtml' => $bids->links('vendor.pagination.bootstrap-5')->render(),
    ]);
  }

  public function detail(Bid $bid)
  {
    $bid->is_seen = true;
    $bid->save();
    $bid->load('proposal');

    return view('_partials.bid-detail', ['bid' => $bid])->render();
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
    return redirect('/bids');
  }

  public function updateBidCheck(Request $request)
  {
    $bid = Bid::find($request->bid_id);

    if (!$bid) {
      return response()->json(['success' => false, 'message' => 'Bid not found.'], 404);
    }

    $bid->check = $request->check;
    $bid->save();

    return response()->json(['success' => true, 'check' => $bid->check]);
  }

  private function needsFeedbackQuery()
  {
    return Bid::needsFeedback()->with('proposal')->orderByDesc('id');
  }

  public function relevance()
  {
    $bids = $this->needsFeedbackQuery()->limit(21)->get();
    $hasMore = $bids->count() > 20;
    $bids = $bids->take(20);
    return view('content.pages.relevance', ['bids' => $bids, 'hasMore' => $hasMore]);
  }

  public function loadRelevance(Request $request)
  {
    $query = $this->needsFeedbackQuery();
    if ($request->filled('after_id')) {
      $query->where('id', '<', (int) $request->input('after_id'));
    }
    $bids = $query->limit(21)->get();
    $hasMore = $bids->count() > 20;
    $bids = $bids->take(20);

    $html = '';
    foreach ($bids as $bid) {
      $html .= view('_partials.relevance-card', ['bid' => $bid])->render();
    }

    return response()->json([
      'html' => $html,
      'hasMore' => $hasMore,
    ]);
  }

  public function storeFeedback(Request $request)
  {
    $validated = $request->validate([
      'bid_id' => 'required|exists:bids,id',
      'feedback' => 'required|in:relevant,irrelevant,scam',
    ]);

    $bid = Bid::find($validated['bid_id']);
    $bid->admin_feedback = $validated['feedback'];
    $bid->save();

    return response()->json(['success' => true]);
  }
}
