<?php

namespace Tests\Feature;

use App\Services\ProposalQualifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProposalQualifierTest extends TestCase
{
    private function fakeReply(string $content, int $status = 200): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]],
                $status
            ),
        ]);
    }

    private function qualifier(): ProposalQualifier
    {
        return new ProposalQualifier();
    }

    public function test_true_reply_returns_qualified_true_with_reason(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "No crypto or gambling; safe web project."}');
        $result = $this->qualifier()->qualify('no crypto', 'A Laravel API project');

        $this->assertTrue($result['qualified']);
        $this->assertStringContainsString('safe web project', $result['reason']);
        Http::assertSentCount(1);
    }

    public function test_false_reply_returns_qualified_false_with_reason(): void
    {
        $this->fakeReply('{"qualified": false, "reason": "Matches negative criteria: crypto trading."}');
        $result = $this->qualifier()->qualify('no crypto', 'A crypto trading bot');

        $this->assertFalse($result['qualified']);
        $this->assertStringContainsString('crypto', $result['reason']);
    }

    public function test_reply_wrapped_in_markdown_fence_is_parsed(): void
    {
        $this->fakeReply("```json\n{\"qualified\": false, \"reason\": \"gambling\"}\n```");
        $result = $this->qualifier()->qualify('no gambling', 'A poker app');

        $this->assertFalse($result['qualified']);
        $this->assertSame('gambling', $result['reason']);
    }

    public function test_reply_with_surrounding_prose_is_parsed(): void
    {
        $this->fakeReply('Sure! {"qualified": false, "reason": "crypto"} Hope this helps.');
        $result = $this->qualifier()->qualify('no crypto', 'A crypto bot');

        $this->assertFalse($result['qualified']);
        $this->assertSame('crypto', $result['reason']);
    }

    public function test_http_error_retries_then_fails_closed(): void
    {
        $this->fakeReply('', 500);
        $result = $this->qualifier()->qualify('x', 'y');

        $this->assertFalse($result['qualified']);
        $this->assertSame('', $result['reason']);
        Http::assertSentCount(2); // initial + 1 retry
    }

    public function test_unparseable_reply_fails_closed(): void
    {
        $this->fakeReply('maybe, not sure');
        $result = $this->qualifier()->qualify('x', 'y');

        $this->assertFalse($result['qualified']);
        $this->assertSame('', $result['reason']);
    }

    public function test_system_message_contains_negative_prompt_and_json_instruction(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "ok"}');
        $this->qualifier()->qualify('no gambling sites', 'A poker app');

        Http::assertSent(function ($request) {
            $system = strtolower($request->data()['messages'][0]['content'] ?? '');
            return str_contains($system, 'no gambling sites')
                && str_contains($system, 'json')
                && str_contains($system, 'qualified')
                && str_contains($system, 'reason');
        });
    }
}
