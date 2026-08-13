<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsurePortalAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::guard('portal')->check()) {
            return redirect('/portal/login');
        }

        Auth::shouldUse('portal');

        return $next($request);
    }
}
