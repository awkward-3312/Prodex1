<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantNotSuspended
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        if ($tenant && in_array($tenant->status, ['suspended', 'cancelled'], true)) {
            abort(403, 'This workspace has been suspended.');
        }

        return $next($request);
    }
}
