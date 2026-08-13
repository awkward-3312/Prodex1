<?php

namespace App\Listeners;

use Stancl\Tenancy\Events\TenancyEnded;

/**
 * Restores the central timezone stashed by ApplyTenantTimezone when tenancy
 * ends, so central code running after a tenant context (e.g. loops over
 * tenants in console commands) is not left in the last tenant's timezone.
 */
class RevertTenantTimezone
{
    public function handle(TenancyEnded $event): void
    {
        $central = config('app.central_timezone');

        if ($central) {
            config(['app.timezone' => $central]);
            date_default_timezone_set($central);
        }
    }
}
