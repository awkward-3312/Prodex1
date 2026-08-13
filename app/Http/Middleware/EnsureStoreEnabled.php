<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureStoreEnabled
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $enabled = DB::table('store_settings')->where('id', 1)->value('enabled');
        } catch (\Throwable) {
            // Table may not exist yet
            return $next($request);
        }

        if (! $enabled) {
            abort(404);
        }

        return $next($request);
    }
}
