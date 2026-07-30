<?php

namespace Tests\Feature;

use App\Jobs\FineTuneBidJob;
use App\Jobs\OpenAIJob;
use App\Jobs\SummarizeReasonJob;
use App\Models\Bid;
use App\Models\Filter;
use App\Models\FreelancerProfile;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIJobProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_chosen_profile_id_on_bid(): void
    {
        Bus::fake([SummarizeReasonJob::class, FineTuneBidJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => 'no crypto', 'summary_prompt' => '', 'prompt' => 'Write a cover letter.']);
        FreelancerProfile::create(['id' => 101, 'title' => 'Web']);
        $proposal = Proposal::factory()->create(['description' => 'A Laravel API', 'max_budget' => 500, 'qualified' => null]);

        Http::fakeSequence('https://api.openai.com/*')
            ->push(['choices' => [['message' => ['content' => '{"qualified": true, "reason": "web", "profile_id": 101}']]]], 200)
            ->push(['choices' => [['message' => ['content' => 'Dear client, ...']]]], 200);

        (new OpenAIJob($proposal))->handle();

        $bid = Bid::where('proposal_id', $proposal->id)->first();
        $this->assertNotNull($bid);
        $this->assertSame(101, (int) $bid->profile_id);
    }

    public function test_empty_negative_with_profiles_selects_without_skipping(): void
    {
        Bus::fake([SummarizeReasonJob::class, FineTuneBidJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => '', 'summary_prompt' => '', 'prompt' => 'Write a cover letter.']);
        FreelancerProfile::create(['id' => 202, 'title' => 'Mobile']);
        $proposal = Proposal::factory()->create(['description' => 'A mobile app', 'max_budget' => 500, 'qualified' => null]);

        Http::fakeSequence('https://api.openai.com/*')
            ->push(['choices' => [['message' => ['content' => '{"qualified": true, "reason": "", "profile_id": 202}']]]], 200)
            ->push(['choices' => [['message' => ['content' => 'Dear client, ...']]]], 200);

        (new OpenAIJob($proposal))->handle();

        $bid = Bid::where('proposal_id', $proposal->id)->first();
        $this->assertNotNull($bid);
        $this->assertSame(202, (int) $bid->profile_id);
    }

    public function test_no_profiles_no_negative_keeps_bid_without_profile(): void
    {
        Bus::fake([SummarizeReasonJob::class, FineTuneBidJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => '', 'summary_prompt' => '', 'prompt' => 'Write a cover letter.']);
        $proposal = Proposal::factory()->create(['description' => 'Anything', 'max_budget' => 500, 'qualified' => null]);

        // Only the cover-letter call happens (no qualify call).
        Http::fake(['https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Dear client, ...']]]], 200)]);

        (new OpenAIJob($proposal))->handle();

        $bid = Bid::where('proposal_id', $proposal->id)->first();
        $this->assertNotNull($bid);
        $this->assertNull($bid->profile_id);
    }
}
