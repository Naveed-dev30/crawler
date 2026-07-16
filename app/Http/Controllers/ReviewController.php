<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    private const NEW_WINDOW_DAYS = 7;
    private const PER_PAGE = 20;

    private function tabQuery(string $tab)
    {
        $cutoff = now()->subDays(self::NEW_WINDOW_DAYS);
        $query = Proposal::needsReview();

        return $tab === 'old'
            ? $query->where('created_at', '<', $cutoff)
            : $query->where('created_at', '>=', $cutoff);
    }

    public function load(Request $request)
    {
        $tab = $request->query('tab') === 'old' ? 'old' : 'new';

        $query = $this->tabQuery($tab)->orderByDesc('id');
        if ($request->filled('after_id')) {
            $query->where('id', '<', (int) $request->query('after_id'));
        }

        $proposals = $query->limit(self::PER_PAGE + 1)->get();
        $hasMore = $proposals->count() > self::PER_PAGE;
        $proposals = $proposals->take(self::PER_PAGE);

        $html = '';
        foreach ($proposals as $proposal) {
            $html .= view('_partials.review-card', ['proposal' => $proposal])->render();
        }

        return response()->json(['html' => $html, 'hasMore' => $hasMore]);
    }

    public function index()
    {
        $proposals = $this->tabQuery('new')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE + 1)
            ->get();
        $hasMore = $proposals->count() > self::PER_PAGE;
        $proposals = $proposals->take(self::PER_PAGE);

        return view('content.pages.review', [
            'proposals' => $proposals,
            'hasMore' => $hasMore,
            'newCount' => $this->tabQuery('new')->count(),
            'oldCount' => $this->tabQuery('old')->count(),
        ]);
    }

    public function storeFeedback(Request $request)
    {
        $validated = $request->validate([
            'proposal_id' => 'required|exists:proposals,id',
            'label' => 'required|in:relevant,not_relevant_skill,scam',
        ]);

        $proposal = Proposal::find($validated['proposal_id']);
        $proposal->review_label = $validated['label'];
        $proposal->save();

        return response()->json(['success' => true]);
    }
}
