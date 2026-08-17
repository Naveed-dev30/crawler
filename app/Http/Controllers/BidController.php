<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBidRequest;
use App\Http\Requests\UpdateBidRequest;
use App\Models\Bid;
use App\Models\BidInsight;
use App\Models\Proposal;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BidController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        // Deep-links from Bid Insights pass ?q=<project_id>. Land on the tab that
        // actually contains that bid (it may be Failed / Skills Not Matched /
        // Not Qualified), not the default Bids Placed tab.
        $activeTab = $request->filled('q') ? $this->locateTab($request->query('q')) : 'completed';

        // ?open=1 additionally pops the project's detail panel on arrival — the
        // Chats page links here that way. Gated behind the flag so existing
        // ?q= deep-links (Bid Insights) keep filtering without opening anything.
        $autoOpenUrl = $request->boolean('open') && $request->filled('q')
            ? $this->detailUrlForProject($request->query('q'))
            : null;

        return view('content.pages.home', [
            'activeTab' => $activeTab,
            'autoOpenUrl' => $autoOpenUrl,
        ]);
    }

    /**
     * The slide-over a project should open into: its bid's panel, or the
     * not-qualified proposal panel when we never bid. Null when the project is
     * unknown here, which leaves the page filtered but with nothing popped —
     * better than opening an empty panel.
     *
     * Resolved server-side so the deep-link does not depend on the row being
     * present in whichever tab and page the table happens to render.
     */
    private function detailUrlForProject(string $projectId): ?string
    {
        $bid = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->where('proposals.project_id', $projectId)
            ->select('bids.id')
            ->latest('bids.created_at')
            ->first();

        if ($bid) {
            return route('bids.detail', $bid->id);
        }

        $proposal = Proposal::where('project_id', $projectId)->first();

        return $proposal ? route('proposals.nq-detail', $proposal->id) : null;
    }

    /**
     * Given a project_id (from a Bid Insights deep-link), work out which bids
     * tab it lives in. Falls back to 'completed' when nothing matches.
     */
    private function locateTab(string $q): string
    {
        $bid = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->where('proposals.project_id', $q)
            ->select('bids.bid_status', 'bids.error_message')
            ->latest('bids.created_at')
            ->first();

        if ($bid) {
            $status = strtolower((string) $bid->bid_status);
            if (in_array($status, ['failed', 'expired'], true)) {
                if (str_contains(strtolower((string) $bid->error_message), 'skill')) {
                    return 'skill-not-matched';
                }

                return Bid::messageIsOutOfBid($bid->error_message) ? 'out-of-bid' : 'failed';
            }

            return 'completed';
        }

        return Proposal::where('project_id', $q)->where('qualified', false)->exists()
            ? 'not-qualified'
            : 'completed';
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
            'total' => (clone $base)->count(),
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

        if ($request->query('tab') === 'not-qualified') {
            // Review sub-tabs: remaining (unreviewed) / Correct / Incorrect
            $nqCheck = in_array($request->query('nqcheck'), ['Correct', 'Incorrect'], true)
              ? $request->query('nqcheck')
              : 'remaining';

            $proposals = Proposal::notQualified()
                ->when($nqCheck === 'remaining', fn ($q) => $q->where(function ($sub) {
                    $sub->whereNull('qualify_check')
                        ->orWhere('qualify_check', '')
                        ->orWhere('qualify_check', 'Unreviewed');
                }))
                ->when(in_array($nqCheck, ['Correct', 'Incorrect'], true), fn ($q) => $q->where('qualify_check', $nqCheck))
                ->when($request->filled('q'), function ($query) use ($request) {
                    $q = $request->query('q');
                    $query->where(function ($sub) use ($q) {
                        $sub->where('title', 'like', "%{$q}%")
                            ->orWhere('project_id', 'like', "%{$q}%");
                    });
                })
                ->orderByDesc('created_at')
                ->paginate(50)
                ->withQueryString();

            $rowsHtml = '';
            foreach ($proposals as $proposal) {
                $rowsHtml .= view('_partials.not-qualified-row', ['proposal' => $proposal, 'checkTab' => $nqCheck])->render();
            }
            if ($proposals->isEmpty()) {
                $rowsHtml = '<tr><td colspan="6" class="text-center text-muted py-4">No not-qualified proposals yet.</td></tr>';
            }

            $lastUpdated = Proposal::max('updated_at');

            return response()->json([
                'cards' => $cards,
                'statusCounts' => $statusCounts,
                'rowsHtml' => $rowsHtml,
                'paginationHtml' => $proposals->links('vendor.pagination.bootstrap-5')->render(),
                'lastUpdated' => $lastUpdated ? Carbon::parse($lastUpdated)->timezone('Asia/Karachi')->format('d M, Y h:i a') : null,
            ]);
        }

        $tab = in_array($request->query('tab'), ['failed', 'skill-not-matched', 'out-of-bid'], true)
          ? $request->query('tab')
          : 'completed';
        $isCompleted = $tab === 'completed';

        // Failed sub-tabs: other (not bid-limit) / out-of-bid (bid limit reached)
        $failSub = in_array($request->query('failsub'), ['other', 'out-of-bid'], true)
          ? $request->query('failsub')
          : 'other';

        // Bids Placed sub-tabs: remaining (unmarked) / Correct / Incorrect
        $checkTab = in_array($request->query('check'), ['Correct', 'Incorrect'], true)
          ? $request->query('check')
          : 'remaining';

        // Skills Not Matched sub-tabs: remaining (unmarked) / Interested / Not Interested
        $interestTab = in_array($request->query('interest'), ['Interested', 'Not Interested'], true)
          ? $request->query('interest')
          : 'remaining';
        $isSkillTab = $tab === 'skill-not-matched';

        $bids = (clone $base)
            ->when($tab === 'completed', fn ($q) => $q->whereIn('bids.bid_status', $placed))
            ->when($isCompleted && $checkTab === 'remaining', fn ($q) => $q->where(function ($sub) {
                $sub->whereNull('bids.check')
                    ->orWhere('bids.check', '')
                    ->orWhere('bids.check', 'Unreviewed');
            }))
            ->when($isCompleted && in_array($checkTab, ['Correct', 'Incorrect'], true), fn ($q) => $q->where('bids.check', $checkTab))
            ->when($isSkillTab && $interestTab === 'remaining', fn ($q) => $q->where(function ($sub) {
                $sub->whereNull('bids.interest')->orWhere('bids.interest', '')->orWhere('bids.interest', 'Unreviewed');
            }))
            ->when($isSkillTab && in_array($interestTab, ['Interested', 'Not Interested'], true), fn ($q) => $q->where('bids.interest', $interestTab))
            ->when($tab === 'skill-not-matched', fn ($q) => $q
                ->whereIn('bids.bid_status', $failed)
                ->where('bids.error_message', 'like', '%skill%'))
            ->when($tab === 'failed', fn ($q) => $q
                ->whereIn('bids.bid_status', $failed)
                ->where(function ($sub) {
                    $sub->where('bids.error_message', 'not like', '%skill%')
                        ->orWhereNull('bids.error_message');
                })
                ->when($failSub === 'other', fn ($qq) => $qq->notOutOfBid())
                ->when($failSub === 'out-of-bid', fn ($qq) => $qq->outOfBid()))
            ->when($tab === 'out-of-bid', fn ($q) => $q
                ->whereIn('bids.bid_status', $failed)
                ->where(function ($sub) {
                    $sub->where('bids.error_message', 'not like', '%skill%')
                        ->orWhereNull('bids.error_message');
                })
                ->outOfBid())
            ->with('proposal')
            ->latest('bids.created_at')
            ->paginate(100)
            ->withQueryString();

        // Club the scraped Bid Insights (rank, winning bid, client, time-to-bid)
        // into each row so this table carries that intel too.
        $insights = BidInsight::whereIn('project_id', $bids->pluck('proposal.project_id')->filter()->all())
            ->get()
            ->keyBy('project_id');

        $rowsHtml = '';
        foreach ($bids as $bid) {
            $rowsHtml .= view('_partials.bid-row', [
                'bid' => $bid,
                'completed' => $isCompleted,
                'checkTab' => $checkTab,
                'skillTab' => $isSkillTab,
                'interestTab' => $interestTab,
                'insight' => $insights[$bid->proposal->project_id] ?? null,
            ])->render();
        }
        if ($bids->isEmpty()) {
            $colspan = $isCompleted ? 9 : 7;
            $rowsHtml = '<tr><td colspan="'.$colspan.'" class="text-center text-muted py-4">No bids match these filters.</td></tr>';
        }

        $lastUpdated = Bid::max('updated_at');

        return response()->json([
            'cards' => $cards,
            'statusCounts' => $statusCounts,
            'rowsHtml' => $rowsHtml,
            'paginationHtml' => $bids->links('vendor.pagination.bootstrap-5')->render(),
            'lastUpdated' => $lastUpdated ? Carbon::parse($lastUpdated)->timezone('Asia/Karachi')->format('d M, Y h:i a') : null,
        ]);
    }

    public function detail(Bid $bid)
    {
        $bid->is_seen = true;
        $bid->save();
        $bid->load('proposal');

        // Who posted the project. Optional: only projects the crawler or a chat
        // sync has resolved a client for have a row.
        $insight = BidInsight::where('project_id', $bid->proposal?->project_id)->first();

        return view('_partials.bid-detail', ['bid' => $bid, 'insight' => $insight])->render();
    }

    public function stats()
    {
        $bidsStats = Bid::latestYear()->whereSeen()->groupByDate()->get();

        $firstDate = new DateTime;

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
    public function store(StoreBidRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @return Response
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
     * @return Response
     */
    public function edit(Bid $bid) {}

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(UpdateBidRequest $request, Bid $bid)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy(Bid $bid)
    {
        //
    }

    public function changeStatus(Request $request)
    {
        $status = $request->status;

        if (! $request->bidId) {
            return;
        }

        // if (!($status == 'completed' or $status == 'failed')) {
        //   return response()->json(['success' => false, 'message' => 'Invalid Status'], 400);
        // }

        $bidId = $request->bidId;

        $bid = Bid::find($bidId);

        if (! $bid) {
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

        if (! $latestBid) {
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

        return redirect('/bids')->with('status', $notCompletedBids->count().' pending bids expired.');
    }

    public function updateBidCheck(Request $request)
    {
        $bid = Bid::find($request->bid_id);

        if (! $bid) {
            return response()->json(['success' => false, 'message' => 'Bid not found.'], 404);
        }

        $bid->check = $request->check;
        $bid->save();

        return response()->json(['success' => true, 'check' => $bid->check]);
    }

    public function updateBidInterest(Request $request)
    {
        $bid = Bid::find($request->bid_id);

        if (! $bid) {
            return response()->json(['success' => false, 'message' => 'Bid not found.'], 404);
        }

        if (! in_array($request->interest, ['Interested', 'Not Interested'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid interest value.'], 422);
        }

        $bid->interest = $request->interest;
        $bid->save();

        return response()->json(['success' => true, 'interest' => $bid->interest]);
    }
}
