<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SafeCreateFreshApiToken
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $class = 'Laravel\\Passport\\Http\\Middleware\\CreateFreshApiToken';

            if (class_exists($class)) {
                return app($class)->handle($request, $next);
            }
        } catch (\Throwable) {
            // Passport tables may not exist yet (e.g. during setup)
        }

        return $next($request);
    }
}
