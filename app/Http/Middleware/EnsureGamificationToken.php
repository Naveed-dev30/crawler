<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGamificationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('variables.gamificationIngestToken');
        $provided = (string) ($request->bearerToken() ?? $request->header('X-Ingest-Token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
