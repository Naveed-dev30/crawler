<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\Proposal;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function index()
    {
        return view('content.pages.stats');
    }

    /**
     * Lifetime + daily category counts. Never affected by the page's date
     * range — daily means today in GMT+5 (00:00–23:59 Asia/Karachi).
     */
    public function overview()
    {
        $placed = ['pending', 'completed'];
        $failed = ['failed', 'expired'];

        $dayStart = Carbon::now('Asia/Karachi')->startOfDay()->setTimezone(config('app.timezone'));
        $dayEnd = Carbon::now('Asia/Karachi')->endOfDay()->setTimezone(config('app.timezone'));

        $countsFor = function (?array $range) use ($placed, $failed) {
            $bids = fn () => Bid::query()->when($range, fn ($q) => $q->whereBetween('created_at', $range));
            $skills = fn () => $bids()->whereIn('bid_status', $failed)->where('error_message', 'like', '%skill%');

            return [
                'placed' => $bids()->whereIn('bid_status', $placed)->count(),
                'placedCorrect' => $bids()->whereIn('bid_status', $placed)->where('check', 'Correct')->count(),
                'placedIncorrect' => $bids()->whereIn('bid_status', $placed)->where('check', 'Incorrect')->count(),
                'failed' => $bids()->whereIn('bid_status', $failed)
                    ->where(function ($s) {
                        $s->where('error_message', 'not like', '%skill%')->orWhereNull('error_message');
                    })->count(),
                'skillNotMatched' => $skills()->count(),
                'skillsInterested' => $skills()->where('interest', 'Interested')->count(),
                'skillsNotInterested' => $skills()->where('interest', 'Not Interested')->count(),
                'notQualified' => Proposal::notQualified()
                    ->when($range, fn ($q) => $q->whereBetween('created_at', $range))->count(),
            ];
        };

        return response()->json([
            'lifetime' => $countsFor(null),
            'daily' => $countsFor([$dayStart, $dayEnd]),
        ]);
    }

    public function bids(Request $request)
    {
        $granularity = $this->resolveGranularity($request);
        [$from, $to] = $this->resolveRange($request);
        $type = in_array($request->query('type'), ['fixed', 'hourly'], true)
            ? $request->query('type')
            : 'all';

        $query = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->select('bids.created_at as created_at', 'bids.bid_status as bid_status', 'bids.awarded as awarded');

        if ($type !== 'all') {
            $query->where('proposals.type', $type);
        }

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'awarded' => 0, 'placed' => 0, 'failed' => 0];
        }

        foreach ($query->get() as $row) {
            $key = $this->bucketKey(Carbon::parse($row->created_at), $granularity);
            if (!isset($data[$key])) {
                continue;
            }
            $status = strtolower($row->bid_status);
            if ($row->awarded) {
                $data[$key]['awarded']++;
            } elseif ($status === 'completed') {
                $data[$key]['placed']++;
            } elseif (in_array($status, ['failed', 'expired'], true)) {
                $data[$key]['failed']++;
            }
        }

        return response()->json(array_values($data));
    }

    private function resolveGranularity(Request $request): string
    {
        $g = $request->query('granularity', 'daily');
        return in_array($g, ['hourly', 'daily', 'weekly', 'monthly'], true) ? $g : 'daily';
    }

    private function resolveRange(Request $request): array
    {
        $now = Carbon::now();

        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : $now->copy();
        if ($to->greaterThan($now)) {
            $to = $now->copy();
        }

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : $to->copy()->subDays(30)->startOfDay();
        if ($from->greaterThan($to)) {
            $from = $to->copy()->subDays(30)->startOfDay();
        }

        return [$from, $to];
    }

    private function bucketKey(Carbon $dt, string $granularity): string
    {
        return match ($granularity) {
            'hourly' => $dt->format('Y-m-d H:00'),
            'weekly' => $dt->format('o-\WW'),
            'monthly' => $dt->format('Y-m'),
            default => $dt->format('Y-m-d'),
        };
    }

    private function bucketSequence(Carbon $from, Carbon $to, string $granularity): array
    {
        $cursor = match ($granularity) {
            'hourly' => $from->copy()->startOfHour(),
            'weekly' => $from->copy()->startOfWeek(),
            'monthly' => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };

        $keys = [];
        while ($cursor <= $to) {
            $keys[] = $this->bucketKey($cursor, $granularity);
            match ($granularity) {
                'hourly' => $cursor->addHour(),
                'weekly' => $cursor->addWeek(),
                'monthly' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    public function value(Request $request)
    {
        $granularity = $this->resolveGranularity($request);
        [$from, $to] = $this->resolveRange($request);

        $rows = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->select(
                'bids.created_at as created_at',
                'bids.bid_status as bid_status',
                'proposals.min_budget as min_budget',
                'proposals.type as type',
                'proposals.exchange_rate as exchange_rate'
            )
            ->get();

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'placed_usd' => 0, 'failed_usd' => 0];
        }

        foreach ($rows as $row) {
            $key = $this->bucketKey(Carbon::parse($row->created_at), $granularity);
            if (!isset($data[$key])) {
                continue;
            }
            $usd = ($row->min_budget ?? 0) * ($row->exchange_rate ?? 1);
            if ($row->type === 'hourly') {
                $usd *= 10;
            }

            $status = strtolower($row->bid_status);
            if (in_array($status, ['pending', 'completed'], true)) {
                $data[$key]['placed_usd'] += $usd;
            } elseif (in_array($status, ['failed', 'expired'], true)) {
                $data[$key]['failed_usd'] += $usd;
            }
        }

        foreach ($data as $key => $row) {
            $data[$key]['placed_usd'] = round($row['placed_usd'], 2);
            $data[$key]['failed_usd'] = round($row['failed_usd'], 2);
        }

        return response()->json(array_values($data));
    }

    public function last24h(Request $request)
    {
        $since = Carbon::now()->subDay();
        $proposals = Proposal::with('bid')->where('created_at', '>=', $since)->get();

        $posted = 0;
        $awarded = 0;
        $skills = [];

        foreach ($proposals as $proposal) {
            $usd = ($proposal->min_budget ?? 0) * ($proposal->exchange_rate ?? 1);
            if ($proposal->type === 'hourly') {
                $usd *= 10;
            }
            $posted += $usd;

            if ($proposal->bid && $proposal->bid->awarded) {
                $native = $proposal->bid->awarded_price ?? $proposal->bid->price;
                $awarded += $native * ($proposal->exchange_rate ?? 1);
                foreach (($proposal->skills ?? []) as $skill) {
                    $skills[$skill] = ($skills[$skill] ?? 0) + 1;
                }
            }
        }

        arsort($skills);
        $skillsOut = [];
        foreach ($skills as $name => $count) {
            $skillsOut[] = ['name' => $name, 'count' => $count];
        }

        return response()->json([
            'value_posted_usd' => round($posted, 2),
            'value_awarded_usd' => round($awarded, 2),
            'skills' => $skillsOut,
        ]);
    }

    public function winRate(Request $request)
    {
        $granularity = $this->resolveGranularity($request);
        [$from, $to] = $this->resolveRange($request);

        $rows = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->where('bids.bid_status', 'completed')
            ->select(
                'bids.created_at as created_at',
                'bids.awarded as awarded',
                'bids.awarded_price as awarded_price',
                'bids.price as price',
                'proposals.exchange_rate as exchange_rate'
            )
            ->get();

        $buckets = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $buckets[$key] = ['bucket' => $key, 'completed' => 0, 'awarded' => 0, 'win_rate' => 0];
        }

        $totalCompleted = 0;
        $totalAwarded = 0;
        $earningsUsd = 0;

        foreach ($rows as $row) {
            $totalCompleted++;
            $key = $this->bucketKey(Carbon::parse($row->created_at), $granularity);
            if (isset($buckets[$key])) {
                $buckets[$key]['completed']++;
            }
            if ($row->awarded) {
                $totalAwarded++;
                if (isset($buckets[$key])) {
                    $buckets[$key]['awarded']++;
                }
                $native = $row->awarded_price ?? $row->price ?? 0;
                $earningsUsd += $native * ($row->exchange_rate ?? 1);
            }
        }

        foreach ($buckets as $key => $b) {
            $buckets[$key]['win_rate'] = $b['completed'] > 0
                ? round(($b['awarded'] / $b['completed']) * 100, 1)
                : 0;
        }

        return response()->json([
            'summary' => [
                'completed'    => $totalCompleted,
                'awarded'      => $totalAwarded,
                'win_rate'     => $totalCompleted > 0 ? round(($totalAwarded / $totalCompleted) * 100, 1) : 0,
                'earnings_usd' => round($earningsUsd, 2),
            ],
            'series' => array_values($buckets),
        ]);
    }

    public function statusBreakdown(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        $statuses = ['pending', 'completed', 'expired', 'failed'];

        $rows = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->whereIn('bids.bid_status', $statuses)
            ->select(
                'bids.bid_status as bid_status',
                'proposals.min_budget as min_budget',
                'proposals.type as type',
                'proposals.exchange_rate as exchange_rate'
            )
            ->get();

        $out = [];
        foreach ($statuses as $s) {
            $out[$s] = ['status' => ucfirst($s), 'count' => 0, 'amount_usd' => 0];
        }

        foreach ($rows as $row) {
            $usd = ($row->min_budget ?? 0) * ($row->exchange_rate ?? 1);
            if ($row->type === 'hourly') {
                $usd *= 10;
            }
            // bid_status casing is inconsistent in the DB (e.g. BidNowJob writes
            // "Failed"); MySQL matches case-insensitively but PHP keys don't.
            $status = strtolower($row->bid_status);
            $out[$status]['count']++;
            $out[$status]['amount_usd'] += $usd;
        }

        foreach ($out as $k => $row) {
            $out[$k]['amount_usd'] = round($row['amount_usd'], 2);
        }

        return response()->json(array_values($out));
    }

    public function countries(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        $rows = Proposal::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->selectRaw('country, COUNT(*) as count')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json(
            $rows->map(fn ($r) => ['country' => $r->country, 'count' => (int) $r->count])->all()
        );
    }
}
