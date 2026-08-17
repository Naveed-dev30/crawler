<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChatAccess
{
    /**
     * Guard the dashboard Chats area. Unlike the rest of the settings area this
     * is not admin-only: mobile agents work their own threads from here as an
     * alternative to the app. Per-thread ownership is enforced in
     * ChatController, since which threads a mobile agent may open depends on
     * the row, not the route.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! ($user->isAdmin() || $user->isMobile())) {
            abort(403);
        }

        return $next($request);
    }
}
