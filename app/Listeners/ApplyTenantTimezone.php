<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenancyInitialized;

/**
 * Applies the tenant's own timezone (settings.timezone) to the runtime as
 * soon as tenancy is initialized, so now()/Carbon and every
 * config('app.timezone') reader operate in the tenant's timezone. The
 * central default is stashed so RevertTenantTimezone can restore it when
 * tenancy ends.
 */
class ApplyTenantTimezone
{
    public function handle(TenancyInitialized $event): void
    {
        try {
            $timezone = DB::table('settings')->value('timezone');
        } catch (\Throwable $e) {
            // Settings table may not exist yet (e.g. during provisioning).
            return;
        }

        if (! $timezone || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        if (! config()->has('app.central_timezone')) {
            config(['app.central_timezone' => config('app.timezone', 'UTC')]);
        }

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
