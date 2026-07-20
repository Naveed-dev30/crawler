<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Falls back to the legacy key: GamificationIngestTest sets it directly
        // via config() in setUp(), bypassing env entirely.
        $expected = (string) (config('variables.ingestToken')
            ?: config('variables.gamificationIngestToken'));

        $provided = (string) ($request->bearerToken() ?? $request->header('X-Ingest-Token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
