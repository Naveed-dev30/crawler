<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobile
{
    /**
     * Allow only mobile-role users into the mobile chat API.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isMobile()) {
            abort(403);
        }

        return $next($request);
    }
}
