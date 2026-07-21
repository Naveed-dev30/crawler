<?php

namespace Tests\Feature;

use Tests\TestCase;

class IngestTokenMiddlewareTest extends TestCase
{
    public function test_both_aliases_resolve_to_the_ingest_middleware(): void
    {
        // Read the protected property directly: the public accessor for it was
        // renamed across Laravel versions, reflection is stable across both.
        $kernel = app(\App\Http\Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middlewareAliases');
        $property->setAccessible(true);
        $aliases = $property->getValue($kernel);

        $this->assertSame(\App\Http\Middleware\EnsureIngestToken::class, $aliases['ingest.token']);
        $this->assertSame(\App\Http\Middleware\EnsureIngestToken::class, $aliases['gamification.token']);
    }

    public function test_falls_back_to_legacy_config_key(): void
    {
        // GamificationIngestTest sets only this key, so the fallback must work.
        config(['variables.ingestToken' => null]);
        config(['variables.gamificationIngestToken' => 'legacy-secret']);

        $middleware = new \App\Http\Middleware\EnsureIngestToken();
        $request = \Illuminate\Http\Request::create('/api/insights/ingest', 'POST');
        $request->headers->set('Authorization', 'Bearer legacy-secret');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_prefers_new_config_key(): void
    {
        config(['variables.ingestToken' => 'new-secret']);
        config(['variables.gamificationIngestToken' => 'legacy-secret']);

        $middleware = new \App\Http\Middleware\EnsureIngestToken();
        $request = \Illuminate\Http\Request::create('/api/insights/ingest', 'POST');
        $request->headers->set('Authorization', 'Bearer new-secret');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_when_no_secret_configured(): void
    {
        config(['variables.ingestToken' => null]);
        config(['variables.gamificationIngestToken' => null]);

        $middleware = new \App\Http\Middleware\EnsureIngestToken();
        $request = \Illuminate\Http\Request::create('/api/insights/ingest', 'POST');
        $request->headers->set('Authorization', 'Bearer anything');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }
}
