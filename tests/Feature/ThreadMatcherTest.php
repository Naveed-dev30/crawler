<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Services\ThreadMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThreadMatcherTest extends TestCase
{
    use RefreshDatabase;

    private array $profiles = [
        7 => 'Laravel and Vue specialist',
        9 => 'Mobile Flutter developer',
    ];

    private function fakeOpenAi(string $content, int $status = 200): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]],
                $status
            ),
        ]);
    }

    public function test_returns_matched_user_id(): void
    {
        $this->fakeOpenAi('{"user_id": 9}');

        $result = app(ThreadMatcher::class)->match('Flutter app', 'Build a Flutter app', $this->profiles);

        $this->assertSame(9, $result);
    }

    public function test_handles_markdown_fenced_reply(): void
    {
        $this->fakeOpenAi("```json\n{\"user_id\": 7}\n```");

        $this->assertSame(7, app(ThreadMatcher::class)->match('t', 'd', $this->profiles));
    }

    public function test_handles_prose_wrapped_reply(): void
    {
        $this->fakeOpenAi('The best match is {"user_id": 7} based on skills.');

        $this->assertSame(7, app(ThreadMatcher::class)->match('t', 'd', $this->profiles));
    }

    public function test_unknown_user_id_retries_then_returns_null(): void
    {
        $this->fakeOpenAi('{"user_id": 12345}');

        $this->assertNull(app(ThreadMatcher::class)->match('t', 'd', $this->profiles));
        Http::assertSentCount(2);
    }

    public function test_http_error_returns_null(): void
    {
        $this->fakeOpenAi('irrelevant', 500);

        $this->assertNull(app(ThreadMatcher::class)->match('t', 'd', $this->profiles));
        Http::assertSentCount(2);
    }

    public function test_empty_profiles_returns_null_without_api_call(): void
    {
        Http::fake();

        $this->assertNull(app(ThreadMatcher::class)->match('t', 'd', []));
        Http::assertNothingSent();
    }

    public function test_custom_profile_match_prompt_from_filters_is_used(): void
    {
        Filter::factory()->create(['profile_match_prompt' => 'CUSTOM MATCH RULES']);
        $this->fakeOpenAi('{"user_id": 7}');

        app(ThreadMatcher::class)->match('t', 'd', $this->profiles);

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            return str_contains($system, 'CUSTOM MATCH RULES');
        });
    }
}
