<?php

namespace Tests\Feature;

use App\Jobs\OpenAIJob;
use App\Models\Bid;
use App\Models\Filter;
use App\Models\Proposal;
use App\Services\AiGate;
use App\Services\ProposalQualifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): AiGate
    {
        return app(AiGate::class);
    }

    public function test_defaults_enabled(): void
    {
        Filter::factory()->create(['id' => 1]);
        $this->assertTrue($this->gate()->enabled());
    }

    public function test_mark_rate_limited_disables_with_reason(): void
    {
        Filter::factory()->create(['id' => 1, 'ai_enabled' => true]);

        $this->gate()->markRateLimited();

        $filter = Filter::find(1);
        $this->assertFalse((bool) $filter->ai_enabled);
        $this->assertSame(AiGate::REASON_RATE_LIMIT, $filter->ai_disabled_reason);
        $this->assertNotNull($filter->ai_disabled_at);
    }

    public function test_qualifier_trips_gate_on_429(): void
    {
        Filter::factory()->create(['id' => 1, 'ai_enabled' => true]);
        config(['variables.openAIKey' => 'test-key']);
        Http::fake(['https://api.openai.com/*' => Http::response('rate limited', 429)]);

        $result = (new ProposalQualifier)->qualify('no crypto', [], 'A project');

        $this->assertTrue($result['error']);
        $this->assertFalse($this->gate()->enabled());        // gate tripped
        Http::assertSentCount(1);                            // no retry on 429
    }

    public function test_openai_job_short_circuits_when_disabled(): void
    {
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => 'no crypto', 'prompt' => 'Write.', 'ai_enabled' => false, 'ai_disabled_reason' => AiGate::REASON_RATE_LIMIT]);
        $proposal = Proposal::factory()->create(['qualified' => null]);
        Http::fake();

        (new OpenAIJob($proposal))->handle();

        Http::assertNothingSent();                                  // no AI calls
        $this->assertNull($proposal->fresh()->qualified);           // untouched
        $this->assertSame(0, Bid::where('proposal_id', $proposal->id)->count());
    }

    public function test_try_enable_stays_off_when_probe_fails(): void
    {
        Filter::factory()->create(['id' => 1, 'ai_enabled' => false, 'ai_disabled_reason' => AiGate::REASON_RATE_LIMIT]);
        config(['variables.openAIKey' => 'test-key']);
        Http::fake(['https://api.openai.com/*' => Http::response('rate limited', 429)]);

        $this->assertFalse($this->gate()->tryEnable());
        $this->assertFalse($this->gate()->enabled());
    }

    public function test_try_enable_turns_on_when_probe_ok(): void
    {
        Filter::factory()->create(['id' => 1, 'ai_enabled' => false, 'ai_disabled_reason' => AiGate::REASON_RATE_LIMIT]);
        config(['variables.openAIKey' => 'test-key']);
        Http::fake(['https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'x']]]], 200)]);

        $this->assertTrue($this->gate()->tryEnable());
        $this->assertTrue($this->gate()->enabled());
        $this->assertNull(Filter::find(1)->ai_disabled_reason);
    }

    public function test_probe_command_recovers_rate_limit_but_not_manual(): void
    {
        config(['variables.openAIKey' => 'test-key']);
        Http::fake(['https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'x']]]], 200)]);

        // Manual disable: cron must leave it off even though OpenAI answers.
        Filter::factory()->create(['id' => 1, 'ai_enabled' => false, 'ai_disabled_reason' => AiGate::REASON_MANUAL]);
        $this->artisan('ai:probe')->assertSuccessful();
        $this->assertFalse($this->gate()->enabled());

        // Rate-limit disable: cron re-enables once OpenAI answers.
        Filter::where('id', 1)->update(['ai_disabled_reason' => AiGate::REASON_RATE_LIMIT]);
        $this->artisan('ai:probe')->assertSuccessful();
        $this->assertTrue($this->gate()->enabled());
    }

    public function test_admin_toggle_enable_requires_probe(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        Filter::factory()->create(['id' => 1, 'ai_enabled' => false, 'ai_disabled_reason' => AiGate::REASON_RATE_LIMIT]);
        config(['variables.openAIKey' => 'test-key']);

        // First probe fails (429), second succeeds (200).
        Http::fakeSequence('https://api.openai.com/*')
            ->push('', 429)
            ->push(['choices' => [['message' => ['content' => 'x']]]], 200);

        // Probe fails → 422, stays off.
        $this->actingAs($admin)->postJson('/ai/toggle', ['enable' => true])->assertStatus(422);
        $this->assertFalse($this->gate()->enabled());

        // Probe ok → enabled.
        $this->actingAs($admin)->postJson('/ai/toggle', ['enable' => true])->assertOk();
        $this->assertTrue($this->gate()->enabled());

        // Disable is immediate, reason=manual.
        $this->actingAs($admin)->postJson('/ai/toggle', ['enable' => false])->assertOk();
        $this->assertFalse($this->gate()->enabled());
        $this->assertSame(AiGate::REASON_MANUAL, Filter::find(1)->ai_disabled_reason);
    }
}
