<?php

namespace Tests\Feature;

use App\Jobs\OpenAIJob;
use App\Jobs\SummarizeReasonJob;
use App\Models\Bid;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIJobGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_reject_flags_proposal_and_skips_bid(): void
    {
        Bus::fake([SummarizeReasonJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => 'no crypto', 'summary_prompt' => 'Summarize.', 'prompt' => 'Write a cover letter.']);
        $proposal = Proposal::factory()->create(['description' => 'A crypto trading bot', 'qualified' => null]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => '{"qualified": false, "reason": "crypto trading"}']]]],
                200
            ),
        ]);

        (new OpenAIJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertFalse($fresh->qualified);
        $this->assertSame('crypto trading', $fresh->qualify_reason);
        $this->assertSame(0, Bid::where('proposal_id', $proposal->id)->count());
        Bus::assertDispatched(SummarizeReasonJob::class);
    }

    public function test_gate_pass_flags_qualified_and_creates_bid(): void
    {
        Bus::fake([SummarizeReasonJob::class, \App\Jobs\FineTuneBidJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => 'no crypto', 'summary_prompt' => '', 'prompt' => 'Write a cover letter.']);
        $proposal = Proposal::factory()->create(['description' => 'A Laravel API', 'max_budget' => 500, 'qualified' => null]);

        // First call = qualifier (JSON), second call = cover letter.
        Http::fakeSequence('https://api.openai.com/*')
            ->push(['choices' => [['message' => ['content' => '{"qualified": true, "reason": "safe web project"}']]]], 200)
            ->push(['choices' => [['message' => ['content' => 'Dear client, ...']]]], 200);

        (new OpenAIJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertTrue($fresh->qualified);
        $this->assertSame('safe web project', $fresh->qualify_reason);
        $this->assertSame(1, Bid::where('proposal_id', $proposal->id)->count());
        // summary_prompt empty → summary job NOT dispatched
        Bus::assertNotDispatched(SummarizeReasonJob::class);
    }
}
