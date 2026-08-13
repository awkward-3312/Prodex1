<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCentralAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::guard('central')->check()) {
            return redirect()->route('central.login');
        }

        Auth::shouldUse('central');

        return $next($request);
    }
}
