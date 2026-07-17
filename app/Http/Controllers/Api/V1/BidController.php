<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BidResource;
use App\Models\Bid;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BidController extends Controller
{
    protected function filteredBidQuery(Request $request)
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

    public function index(Request $request)
    {
        $placed = ['pending', 'completed'];
        $failed = ['failed', 'expired'];

        $base = $this->filteredBidQuery($request);

        $cards = [
            'total'  => (clone $base)->count(),
            'placed' => (clone $base)->whereIn('bids.bid_status', $placed)->count(),
            'failed' => (clone $base)->whereIn('bids.bid_status', $failed)->count(),
        ];

        $tab = in_array($request->query('tab'), ['failed', 'completed'], true)
            ? $request->query('tab')
            : 'placed';
        $statuses = match ($tab) {
            'failed' => $failed,
            'completed' => ['completed'],
            default => $placed,
        };

        $bids = (clone $base)
            ->whereIn('bids.bid_status', $statuses)
            ->with('proposal')
            ->latest('bids.created_at')
            ->paginate(100)
            ->withQueryString();

        return response()->json([
            'data' => BidResource::collection($bids->items()),
            'cards' => $cards,
            'meta' => [
                'current_page' => $bids->currentPage(),
                'last_page' => $bids->lastPage(),
                'per_page' => $bids->perPage(),
                'total' => $bids->total(),
            ],
        ]);
    }

    public function show(Bid $bid)
    {
        $bid->is_seen = true;
        $bid->save();
        $bid->load('proposal');

        return response()->json([
            'data' => (new BidResource($bid))->withFull(),
        ]);
    }
}
