<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckCentralPermission
{
    public function handle(Request $request, Closure $next, string ...$sections)
    {
        $user = Auth::guard('central')->user();

        if (! $user) {
            return redirect()->route('central.login');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        foreach ($sections as $section) {
            if ($user->hasPermission($section)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to access this section.');
    }
}
