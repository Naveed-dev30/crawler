<?php

namespace Tests\Feature;

use App\Services\ProposalQualifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProposalQualifierProfileTest extends TestCase
{
    private function fakeReply(string $content): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]], 200
            ),
        ]);
    }

    public function test_returns_valid_profile_id_when_model_picks_listed_id(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "web", "profile_id": 101}');

        $out = (new ProposalQualifier)->qualify('no crypto', [['id' => 101, 'title' => 'Web'], ['id' => 202, 'title' => 'Mobile']], 'A Laravel API');

        $this->assertTrue($out['qualified']);
        $this->assertSame(101, $out['profile_id']);
    }

    public function test_unknown_profile_id_becomes_null(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "web", "profile_id": 999}');

        $out = (new ProposalQualifier)->qualify('no crypto', [['id' => 101, 'title' => 'Web']], 'A Laravel API');

        $this->assertTrue($out['qualified']);
        $this->assertNull($out['profile_id']);
    }

    public function test_no_profiles_yields_null_profile_id(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "web"}');

        $out = (new ProposalQualifier)->qualify('no crypto', [], 'A Laravel API');

        $this->assertTrue($out['qualified']);
        $this->assertNull($out['profile_id']);
    }

    public function test_failure_is_fail_closed(): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response('err', 500)]);

        $out = (new ProposalQualifier)->qualify('no crypto', [['id' => 101, 'title' => 'Web']], 'A Laravel API');

        $this->assertFalse($out['qualified']);
        $this->assertSame('', $out['reason']);
        $this->assertNull($out['profile_id']);
    }
}
