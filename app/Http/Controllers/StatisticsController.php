<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use App\Services\MobileAgentStats;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function index()
    {
        return view('content.pages.stats');
    }

    /**
     * Category counts for the dashboard's headline card. Driven by the page's
     * shared date range like every other section — pick the All preset for
     * lifetime totals, Today for the day so far.
     */
    public function overview(Request $request)
    {
        $placed = ['pending', 'completed'];
        $failed = ['failed', 'expired'];

        [$from, $to] = $this->resolveRange($request);

        $countsFor = function (array $range) use ($placed, $failed) {
            $bids = fn () => Bid::query()->whereBetween('created_at', $range);
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
                'notQualified' => Proposal::notQualified()->whereBetween('created_at', $range)->count(),
            ];
        };

        return response()->json([
            'counts' => $countsFor([$from, $to]),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
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
            ->select(
                'bids.created_at as created_at',
                'bids.bid_status as bid_status',
                'bids.error_message as error_message',
                'bids.awarded as awarded'
            );

        if ($type !== 'all') {
            $query->where('proposals.type', $type);
        }

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'awarded' => 0, 'placed' => 0, 'failed' => 0, 'skills' => 0, 'nq' => 0];
        }

        $bump = function (string $series, $bucketedAt) use (&$data, $granularity) {
            $key = $this->bucketKey(Carbon::parse($bucketedAt), $granularity);
            if (isset($data[$key])) {
                $data[$key][$series]++;
            }
        };

        foreach ($query->get() as $row) {
            $status = strtolower($row->bid_status);
            if ($row->awarded) {
                $bump('awarded', $row->created_at);
            } elseif ($status === 'completed') {
                $bump('placed', $row->created_at);
            } elseif (in_array($status, ['failed', 'expired'], true)) {
                // Skill failures get their own series, same split as the donut.
                $bump($this->categoryFor($row->bid_status, $row->error_message) === 'skills' ? 'skills' : 'failed', $row->created_at);
            }
        }

        // Not Qualified projects are skipped before a bid exists, so they are
        // bucketed by when the project came in.
        Proposal::notQualified()
            ->whereBetween('created_at', [$from, $to])
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->get(['created_at'])
            ->each(fn ($p) => $bump('nq', $p->created_at));

        return response()->json(array_values($data));
    }

    /**
     * An hourly project has no total budget, so its rate counts as ten hours.
     * The status donut, the value chart and the country list all price a
     * project this way — keep them saying the same number.
     */
    private const HOURLY_HOURS = 10;

    private function usdValue($minBudget, $exchangeRate, ?string $type): float
    {
        $usd = (float) ($minBudget ?? 0) * (float) ($exchangeRate ?? 1);

        return $type === 'hourly' ? $usd * self::HOURLY_HOURS : $usd;
    }

    /** The same rule as usdValue(), pushed into SQL for grouped aggregates. */
    private function usdValueSql(string $table = 'proposals'): string
    {
        return "SUM(COALESCE({$table}.min_budget, 0) * COALESCE({$table}.exchange_rate, 1)"
            ." * CASE WHEN {$table}.type = 'hourly' THEN ".self::HOURLY_HOURS.' ELSE 1 END)';
    }

    /**
     * Which of the dashboard's four categories a bid falls into. bid_status
     * casing is inconsistent in the DB (BidNowJob writes "Failed"); MySQL
     * matches case-insensitively but PHP keys don't. The fourth category,
     * Not Qualified, never has a bid at all — it comes off proposals.
     */
    private function categoryFor(?string $bidStatus, ?string $errorMessage): string
    {
        $status = strtolower((string) $bidStatus);

        if (in_array($status, ['pending', 'completed'], true)) {
            return 'placed';
        }

        return str_contains(strtolower((string) $errorMessage), 'skill') ? 'skills' : 'failed';
    }

    private function resolveGranularity(Request $request): string
    {
        $g = $request->query('granularity', 'daily');

        return in_array($g, ['hourly', 'daily', 'weekly', 'monthly'], true) ? $g : 'daily';
    }

    /**
     * The dashboard's shared window. `all=1` is the All preset: everything we
     * have ever recorded, anchored to the oldest row rather than to a magic
     * date so the buckets stay tight.
     */
    private function resolveRange(Request $request): array
    {
        $now = Carbon::now();

        if ($request->boolean('all')) {
            $oldest = collect([Bid::min('created_at'), Proposal::min('created_at')])
                ->filter()
                ->map(fn ($d) => Carbon::parse($d))
                ->min();

            return [($oldest ?? $now->copy()->subDays(30))->copy()->startOfDay(), $now->copy()];
        }

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

        // An hourly all-time request would ask for tens of thousands of buckets
        // and hang the browser; the UI coarsens the granularity itself, this is
        // only the backstop for a hand-written URL.
        $limit = 1000;

        $keys = [];
        while ($cursor <= $to && count($keys) < $limit) {
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
            ->whereIn('bids.bid_status', ['pending', 'completed', 'expired', 'failed'])
            ->select(
                'bids.created_at as created_at',
                'bids.bid_status as bid_status',
                'bids.error_message as error_message',
                'proposals.min_budget as min_budget',
                'proposals.type as type',
                'proposals.exchange_rate as exchange_rate'
            )
            ->get();

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'placed_usd' => 0, 'failed_usd' => 0, 'skills_usd' => 0, 'nq_usd' => 0];
        }

        $add = function (string $category, $bucketedAt, $minBudget, $exchangeRate, $type) use (&$data, $granularity) {
            $key = $this->bucketKey(Carbon::parse($bucketedAt), $granularity);
            if (isset($data[$key])) {
                $data[$key][$category.'_usd'] += $this->usdValue($minBudget, $exchangeRate, $type);
            }
        };

        foreach ($rows as $row) {
            $add(
                $this->categoryFor($row->bid_status, $row->error_message),
                $row->created_at, $row->min_budget, $row->exchange_rate, $row->type
            );
        }

        // Not Qualified projects are skipped before a bid exists, so they are
        // bucketed by when the project came in.
        Proposal::notQualified()
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'min_budget', 'type', 'exchange_rate'])
            ->each(fn ($p) => $add('nq', $p->created_at, $p->min_budget, $p->exchange_rate, $p->type));

        foreach ($data as $key => $row) {
            foreach (['placed_usd', 'failed_usd', 'skills_usd', 'nq_usd'] as $field) {
                $data[$key][$field] = round($row[$field], 2);
            }
        }

        return response()->json(array_values($data));
    }

    /**
     * Value posted vs awarded, plus the skills behind the awards, over the
     * page's shared date range.
     */
    public function snapshot(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $proposals = Proposal::with('bid')->whereBetween('created_at', [$from, $to])->get();

        $posted = 0;
        $awarded = 0;
        $skills = [];

        foreach ($proposals as $proposal) {
            $posted += $this->usdValue($proposal->min_budget, $proposal->exchange_rate, $proposal->type);

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
                'completed' => $totalCompleted,
                'awarded' => $totalAwarded,
                'win_rate' => $totalCompleted > 0 ? round(($totalAwarded / $totalCompleted) * 100, 1) : 0,
                'earnings_usd' => round($earningsUsd, 2),
            ],
            'series' => array_values($buckets),
        ]);
    }

    public function statusBreakdown(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        $rows = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->whereIn('bids.bid_status', ['pending', 'completed', 'expired', 'failed'])
            ->select(
                'bids.bid_status as bid_status',
                'bids.error_message as error_message',
                'proposals.min_budget as min_budget',
                'proposals.type as type',
                'proposals.exchange_rate as exchange_rate'
            )
            ->get();

        $out = [
            'placed' => ['status' => 'Bids Placed', 'count' => 0, 'amount_usd' => 0],
            'failed' => ['status' => 'Failed', 'count' => 0, 'amount_usd' => 0],
            'skills' => ['status' => 'Skills Not Matched', 'count' => 0, 'amount_usd' => 0],
            'nq' => ['status' => 'Not Qualified', 'count' => 0, 'amount_usd' => 0],
        ];

        foreach ($rows as $row) {
            $key = $this->categoryFor($row->bid_status, $row->error_message);
            $out[$key]['count']++;
            $out[$key]['amount_usd'] += $this->usdValue($row->min_budget, $row->exchange_rate, $row->type);
        }

        Proposal::notQualified()
            ->whereBetween('created_at', [$from, $to])
            ->get(['min_budget', 'type', 'exchange_rate'])
            ->each(function ($p) use (&$out) {
                $out['nq']['count']++;
                $out['nq']['amount_usd'] += $this->usdValue($p->min_budget, $p->exchange_rate, $p->type);
            });

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
            ->selectRaw('country, COUNT(*) as count, '.$this->usdValueSql().' as amount_usd')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json(
            $rows->map(fn ($r) => [
                'country' => $r->country,
                'count' => (int) $r->count,
                'amount_usd' => round((float) $r->amount_usd, 2),
            ])->all()
        );
    }

    public function mobileAgents(Request $request, MobileAgentStats $stats)
    {
        [$from, $to] = $this->resolveRange($request);

        return response()->json(['rows' => $stats->rows($from, $to)]);
    }

    public function mobileAgentActivity(Request $request, User $user, MobileAgentStats $stats)
    {
        abort_unless($user->role === 'mobile', 403);
        [$from, $to] = $this->resolveRange($request);

        return response()->json(['items' => $stats->activityFor($user, $from, $to)]);
    }
}
