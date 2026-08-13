<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ServeSetupWhenNotInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (file_exists(base_path('storage/app/public/installed'))) {
            return $next($request);
        }

        // Allow setup routes and their assets through; redirect everything else.
        $path = $request->path();
        if (str_starts_with($path, 'setup') || str_starts_with($path, 'assets_setup')) {
            return $next($request);
        }

        return redirect('/setup');
    }
}
