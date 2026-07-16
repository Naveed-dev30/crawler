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

    public function test_true_reply_returns_true_and_sends_one_request(): void
    {
        $this->fakeReply('true');
        $this->assertTrue($this->qualifier()->qualify('no crypto', 'A Laravel API project'));
        Http::assertSentCount(1);
    }

    public function test_false_reply_returns_false(): void
    {
        $this->fakeReply('false');
        $this->assertFalse($this->qualifier()->qualify('no crypto', 'A crypto trading bot'));
        Http::assertSentCount(1);
    }

    public function test_true_reply_is_normalized_with_whitespace(): void
    {
        $this->fakeReply("TRUE\n");
        $this->assertTrue($this->qualifier()->qualify('x', 'y'));
    }

    public function test_false_reply_is_normalized_with_punctuation(): void
    {
        $this->fakeReply(' false. ');
        $this->assertFalse($this->qualifier()->qualify('x', 'y'));
    }

    public function test_ambiguous_reply_retries_then_fails_closed(): void
    {
        $this->fakeReply('maybe not sure');
        $this->assertFalse($this->qualifier()->qualify('x', 'y'));
        Http::assertSentCount(2); // initial + 1 retry
    }

    public function test_http_error_retries_then_fails_closed(): void
    {
        $this->fakeReply('', 500);
        $this->assertFalse($this->qualifier()->qualify('x', 'y'));
        Http::assertSentCount(2);
    }

    public function test_system_message_contains_negative_prompt_and_instruction(): void
    {
        $this->fakeReply('true');
        $this->qualifier()->qualify('no gambling sites', 'A poker app');

        Http::assertSent(function ($request) {
            $system = strtolower($request->data()['messages'][0]['content'] ?? '');
            return str_contains($system, 'no gambling sites')
                && str_contains($system, 'strict project filter')
                && str_contains($system, 'reply only true or false');
        });
    }
}
