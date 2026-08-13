<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectSetupToCentral
{
    public function handle(Request $request, Closure $next)
    {
        if (str_starts_with($request->path(), 'setup')) {
            $central = config('tenancy.central_domains', [])[0] ?? 'localhost';
            $protocol = $request->secure() ? 'https' : 'http';
            $port = $request->getPort();
            $portSuffix = ($port && ! in_array($port, [80, 443])) ? ":{$port}" : '';

            return redirect("{$protocol}://{$central}{$portSuffix}/setup");
        }

        return $next($request);
    }
}
