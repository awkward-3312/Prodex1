<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns the process timezone with the tenant's configured timezone for the
 * whole request, so that:
 *
 *   - the POS writes `Sale.date` / `Sale.time` (Carbon::now()) in the business's
 *     own day, not UTC; and
 *   - the Dashboard / report date windows (now()->subDays(...), the client's
 *     browser-local from/to) resolve against that same day.
 *
 * Without this, a sale made in the evening in a UTC-negative country is stamped
 * with the next calendar day and silently drops out of "today"/range widgets
 * while still showing up in "recent sales" (which has no date filter).
 *
 * The tenant timezone already exists (`settings.timezone`) and
 * SettingsController already applies it to the running process when it is saved;
 * this middleware just makes that happen on every tenant request instead of only
 * right after a save. It is regional, not Honduras-specific, and is a no-op for
 * tenants left on the default "UTC".
 */
class ApplyTenantTimezone
{
    public function handle(Request $request, Closure $next)
    {
        $tz = $this->tenantTimezone();

        if ($tz !== null && $tz !== (string) config('app.timezone')) {
            config(['app.timezone' => $tz]);
            date_default_timezone_set($tz);
        }

        return $next($request);
    }

    private function tenantTimezone(): ?string
    {
        try {
            if (! Schema::hasTable('settings')) {
                return null;
            }

            $tz = optional(Setting::query()->first())->timezone;

            if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        } catch (\Throwable $e) {
            // Never fail a request over timezone resolution.
        }

        return null;
    }
}
