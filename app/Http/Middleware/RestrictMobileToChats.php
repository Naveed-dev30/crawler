<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictMobileToChats
{
    /**
     * Mobile agents may sign in to the dashboard, but Chats is the only screen
     * they get — the rest (bids, stats, insights, settings) is not theirs to
     * see. Hiding the menu entries is not enough on its own; a typed URL has to
     * bounce too.
     *
     * Routes a chat-only session still legitimately needs.
     */
    private const ALLOWED = [
        'chats',
        'chats.rows',
        'chats.detail',
        'chats.assign',
        'chats.unblock',
        'chats.message',
        'logout',
        'attachments.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isMobile() || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        // A navigation lands on the one page they can use — this is also what
        // turns the post-login redirect to "/" into the Chats screen. Anything
        // else is a deliberate poke at another surface, so say no plainly
        // instead of bouncing a write to a page that cannot show its result.
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('chats');
        }

        abort(403);
    }
}
