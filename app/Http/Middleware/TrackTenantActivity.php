<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TrackTenantActivity
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        if ($tenant) {
            $lastActivity = $tenant->last_activity_at;

            if (! $lastActivity || $lastActivity->diffInMinutes(now()) >= 15) {
                $tenant->timestamps = false;
                $tenant->update(['last_activity_at' => now()]);
                $tenant->timestamps = true;
            }
        }

        return $next($request);
    }
}
