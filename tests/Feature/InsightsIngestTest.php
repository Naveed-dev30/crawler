<?php

namespace Tests\Feature;

use App\Models\PageCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsIngestTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.ingestToken' => self::TOKEN]);
    }

    private const BIDS = '/api/insights/bids/ingest';
    private const OVERVIEW = '/api/insights/overview/ingest';

    private function body(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://www.freelancer.com/insights/bids',
            'scraped_at' => '2026-07-20T09:00:00Z',
            'payload' => ['bids' => 42],
        ], $overrides);
    }

    // NOT named post(): TestCase inherits a public post() from
    // MakesHttpRequests, and redeclaring it private is a PHP fatal error.
    private function postCapture(array $body, string $route = self::BIDS)
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson($route, $body);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson(self::BIDS, $this->body())->assertStatus(401);
        $this->assertSame(0, PageCapture::count());
    }

    public function test_rejects_wrong_token(): void
    {
        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson(self::BIDS, $this->body())
            ->assertStatus(401);
        $this->assertSame(0, PageCapture::count());
    }

    public function test_overview_route_also_requires_a_token(): void
    {
        $this->postJson(self::OVERVIEW, $this->body())->assertStatus(401);
        $this->assertSame(0, PageCapture::count());
    }

    public function test_bids_route_stores_the_bids_source(): void
    {
        $this->postCapture($this->body())
            ->assertOk()
            ->assertJson(['success' => true, 'unchanged' => false]);

        $capture = PageCapture::firstOrFail();
        $this->assertSame('insights_bids', $capture->source);
        $this->assertSame(hash('sha256', json_encode(['bids' => 42])), $capture->content_hash);
    }

    public function test_overview_route_stores_the_insights_source(): void
    {
        $this->postCapture($this->body(), self::OVERVIEW)->assertOk();

        $this->assertSame('insights', PageCapture::firstOrFail()->source);
    }

    public function test_source_comes_from_the_route_not_the_body(): void
    {
        // A body-supplied source must be ignored entirely: posting to the bids
        // route can never produce an 'insights' row, however the client labels it.
        $this->postCapture($this->body(['source' => 'insights']))->assertOk();

        $this->assertSame('insights_bids', PageCapture::firstOrFail()->source);
    }

    public function test_the_two_routes_write_independent_rows(): void
    {
        $this->postCapture($this->body())->assertOk();
        $this->postCapture($this->body(), self::OVERVIEW)->assertOk();

        $this->assertSame(2, PageCapture::count());
        $this->assertSame(
            ['insights', 'insights_bids'],
            PageCapture::orderBy('source')->pluck('source')->all()
        );
    }

    public function test_rejects_missing_payload(): void
    {
        $body = $this->body();
        unset($body['payload']);

        $this->postCapture($body)->assertStatus(422);
        $this->assertSame(0, PageCapture::count());
    }

    public function test_accepts_html_string_payload(): void
    {
        $html = '<html><body>hi</body></html>';

        $this->postCapture($this->body(['payload' => $html]))->assertOk();

        $this->assertSame($html, PageCapture::firstOrFail()->payload);
    }

    public function test_reposting_same_source_and_scraped_at_is_idempotent(): void
    {
        $this->postCapture($this->body())->assertOk();
        $this->postCapture($this->body())->assertOk();

        $this->assertSame(1, PageCapture::count());
    }

    public function test_identical_content_at_new_timestamp_reports_unchanged(): void
    {
        $this->postCapture($this->body())->assertOk();

        $this->postCapture($this->body(['scraped_at' => '2026-07-21T09:00:00Z']))
            ->assertOk()
            ->assertJson(['unchanged' => true]);

        $this->assertSame(2, PageCapture::count());
    }

    public function test_different_content_at_new_timestamp_reports_changed(): void
    {
        $this->postCapture($this->body())->assertOk();

        $this->postCapture($this->body([
            'scraped_at' => '2026-07-21T09:00:00Z',
            'payload' => ['bids' => 99],
        ]))->assertOk()->assertJson(['unchanged' => false]);
    }

    public function test_invalid_scraped_at_does_not_500(): void
    {
        $this->postCapture($this->body(['scraped_at' => 'not-a-date']))->assertSuccessful();
        $this->assertSame(1, PageCapture::count());
    }
}
