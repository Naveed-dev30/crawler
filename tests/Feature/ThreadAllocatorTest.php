<?php

namespace Tests\Feature;

use App\Services\ThreadAllocator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThreadAllocatorTest extends TestCase
{
    public function test_returns_number_when_in_valid_set(): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"number": 7}']]],
        ])]);

        $n = app(ThreadAllocator::class)->allocate('Build app', 'Flutter work', 'Route it', [7, 9]);

        $this->assertSame(7, $n);
    }

    public function test_returns_null_when_number_not_in_set(): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"number": 3}']]],
        ])]);

        $n = app(ThreadAllocator::class)->allocate('x', 'y', 'p', [7, 9]);

        $this->assertNull($n);
    }

    public function test_returns_null_on_http_failure(): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response('boom', 500)]);

        $n = app(ThreadAllocator::class)->allocate('x', 'y', 'p', [1]);

        $this->assertNull($n);
    }
}
