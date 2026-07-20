<?php

namespace Tests\Feature;

use App\Jobs\SummarizeReasonJob;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SummarizeReasonJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSummary(string $content): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]],
                200
            ),
        ]);
    }

    public function test_summarizes_proposal_not_reason(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => 'Summarize this project in two lines.']);
        $p = Proposal::factory()->create([
            'title' => 'Crypto trading bot',
            'description' => 'Build an automated MT5 trading bot with backtesting.',
            'qualified' => false,
            'qualify_reason' => 'Matches crypto criteria',
            'qualify_summary' => null,
        ]);
        $this->fakeSummary("Line one.\nLine two.");

        (new SummarizeReasonJob($p))->handle();

        $this->assertSame("Line one.\nLine two.", $p->fresh()->qualify_summary);
        Http::assertSent(function ($request) use ($p) {
            $user = $request['messages'][1]['content'] ?? '';

            return str_contains($user, $p->title)
                && str_contains($user, $p->description)
                && ! str_contains($user, 'Matches crypto criteria');
        });
        Http::assertSentCount(1);
    }

    public function test_does_nothing_without_summary_prompt(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => '']);
        $p = Proposal::factory()->create(['qualify_reason' => 'Matches crypto criteria', 'qualify_summary' => null]);
        Http::fake();

        (new SummarizeReasonJob($p))->handle();

        $this->assertNull($p->fresh()->qualify_summary);
        Http::assertNothingSent();
    }

    public function test_does_nothing_without_description(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => 'Summarize.']);
        $p = Proposal::factory()->create(['title' => '', 'description' => '', 'qualify_summary' => null]);
        Http::fake();

        (new SummarizeReasonJob($p))->handle();

        $this->assertNull($p->fresh()->qualify_summary);
        Http::assertNothingSent();
    }
}
