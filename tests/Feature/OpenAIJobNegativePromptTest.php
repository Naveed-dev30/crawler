<?php

namespace Tests\Feature;

use App\Jobs\OpenAIJob;
use App\Models\Bid;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OpenAIJobNegativePromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // prevent FineTuneBidJob from running real OpenAI calls
    }

    private function seedFilter(string $negativePrompt): void
    {
        Filter::factory()->create([
            'id' => 1,
            'crawler_on' => true,
            'prompt' => 'Write a cover letter.',
            'negative_prompt' => $negativePrompt,
        ]);
    }

    private function makeProposal(): Proposal
    {
        return Proposal::factory()->create([
            'description' => 'A Laravel API project',
            'max_budget' => 1000,
        ]);
    }

    /**
     * Fake OpenAI: the qualify call (system message contains "strict project filter")
     * returns $verdict; any other call (the cover letter) returns letter text.
     */
    private function fakeOpenAI(string $verdict, int $qualifyStatus = 200): void
    {
        Http::fake(function ($request) use ($verdict, $qualifyStatus) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            if (str_contains($system, 'strict project filter')) {
                return Http::response(['choices' => [['message' => ['content' => $verdict]]]], $qualifyStatus);
            }
            return Http::response(['choices' => [['message' => ['content' => 'Generated cover letter']]]], 200);
        });
    }

    public function test_empty_negative_prompt_creates_bid_without_qualify_call(): void
    {
        $this->seedFilter('');
        $this->fakeOpenAI('true');
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(1, Bid::count());
        Http::assertSentCount(1); // only the cover-letter call, no qualify call
    }

    public function test_qualify_true_creates_bid(): void
    {
        $this->seedFilter('no crypto');
        $this->fakeOpenAI('true');
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(1, Bid::count());
        Http::assertSentCount(2); // qualify + cover letter
    }

    public function test_qualify_false_skips_bid(): void
    {
        $this->seedFilter('no crypto');
        $this->fakeOpenAI('false');
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(0, Bid::count());
        Http::assertSentCount(1); // only the qualify call; cover letter never reached
    }

    public function test_qualify_error_skips_bid_fail_closed(): void
    {
        $this->seedFilter('no crypto');
        $this->fakeOpenAI('', 500); // qualify returns 500 on every attempt
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(0, Bid::count());
        Http::assertSentCount(2); // 2 qualify attempts, then fail-closed skip
    }
}
