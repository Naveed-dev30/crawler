<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordMobileApiActivity
{
    /**
     * Record that the mobile app called the API, which is what the users page
     * reports as "Last Login". Stamped before the route runs so a request that
     * errors still counts as the app being alive.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->touchApiActivity();

        return $next($request);
    }
}
