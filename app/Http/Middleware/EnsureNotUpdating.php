<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureNotUpdating
{
    public const LOCK_FILE = 'platform-update.lock';

    public function handle(Request $request, Closure $next)
    {
        $lock = storage_path('app/' . self::LOCK_FILE);
        if (! file_exists($lock)) {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');

        $whitelist = [
            '#^/update(/|$)#',
            '#^/super/login$#',
            '#^/super/logout$#',
        ];
        foreach ($whitelist as $re) {
            if (preg_match($re, $path)) {
                return $next($request);
            }
        }

        $info = @json_decode((string) @file_get_contents($lock), true) ?: [];

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'maintenance' => true,
                'message'     => 'Platform is updating. Please try again shortly.',
                'started_at'  => $info['started_at'] ?? null,
            ], 503);
        }

        return response()->view('update.maintenance', [
            'info' => $info,
        ], 503);
    }
}
