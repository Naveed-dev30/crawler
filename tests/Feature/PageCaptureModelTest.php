<?php

namespace Tests\Feature;

use App\Models\PageCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageCaptureModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_and_casts_a_capture(): void
    {
        $capture = PageCapture::create([
            'source' => 'insights_bids',
            'url' => 'https://www.freelancer.com/insights/bids',
            'scraped_at' => '2026-07-20T09:00:00Z',
            'payload' => '{"a":1}',
            'content_hash' => str_repeat('a', 64),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $capture->fresh()->scraped_at);
        $this->assertSame('{"a":1}', $capture->fresh()->payload);
    }

    public function test_source_and_scraped_at_are_unique_together(): void
    {
        $attrs = [
            'source' => 'insights',
            'url' => 'https://www.freelancer.com/insights/',
            'scraped_at' => '2026-07-20T09:00:00Z',
            'payload' => '{}',
            'content_hash' => str_repeat('b', 64),
        ];

        PageCapture::create($attrs);

        $this->expectException(\Illuminate\Database\QueryException::class);
        PageCapture::create($attrs);
    }

    public function test_same_scraped_at_different_source_is_allowed(): void
    {
        PageCapture::create([
            'source' => 'insights',
            'url' => 'https://www.freelancer.com/insights/',
            'scraped_at' => '2026-07-20T09:00:00Z',
            'payload' => '{}',
            'content_hash' => str_repeat('c', 64),
        ]);

        PageCapture::create([
            'source' => 'insights_bids',
            'url' => 'https://www.freelancer.com/insights/bids',
            'scraped_at' => '2026-07-20T09:00:00Z',
            'payload' => '{}',
            'content_hash' => str_repeat('d', 64),
        ]);

        $this->assertSame(2, PageCapture::count());
    }

    public function test_stores_large_html_payload_intact(): void
    {
        $html = '<html><body>' . str_repeat('x', 100000) . '</body></html>';

        PageCapture::create([
            'source' => 'insights',
            'url' => 'https://www.freelancer.com/insights/',
            'scraped_at' => '2026-07-20T10:00:00Z',
            'payload' => $html,
            'content_hash' => hash('sha256', $html),
        ]);

        $this->assertSame($html, PageCapture::firstOrFail()->payload);
    }
}
