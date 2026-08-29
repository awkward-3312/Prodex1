<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Rejects Passport tokens that have been idle for longer than
 * config('auth.api_token_idle_timeout') minutes.
 *
 * Disabled by default (timeout <= 0) so existing sessions are unaffected until
 * an operator opts in via the API_TOKEN_IDLE_TIMEOUT env var.
 */
class EnforceApiTokenTimeout
{
    public function handle(Request $request, Closure $next)
    {
        $timeoutMinutes = (int) config('auth.api_token_idle_timeout', 0);

        if ($timeoutMinutes <= 0) {
            return $next($request);
        }

        $user = $request->user('api') ?: $request->user();
        $token = is_object($user) && method_exists($user, 'token') ? $user->token() : null;
        $tokenId = is_object($token) && isset($token->id) ? $token->id : null;

        // Cookie/session (TransientToken) requests have no token id — nothing to
        // time out here; the web session lifetime already governs those.
        if ($tokenId === null) {
            return $next($request);
        }

        $key = 'api_token_activity:' . $tokenId;
        $now = time();
        $last = Cache::get($key);

        if ($last !== null && ($now - (int) $last) > $timeoutMinutes * 60) {
            Cache::forget($key);

            return response()->json([
                'error' => true,
                'code'  => 'token_idle_timeout',
                'msg'   => 'Tu sesión ha expirado por inactividad. Inicia sesión nuevamente.',
            ], 401);
        }

        // Slide the window. Keep the cache entry alive comfortably past the
        // timeout so an active user is never spuriously logged out.
        Cache::put($key, $now, now()->addMinutes(max($timeoutMinutes * 2, 120)));

        return $next($request);
    }
}
