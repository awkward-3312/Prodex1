<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenancyBootstrapped;

/**
 * After stancl/tenancy bootstraps the tenant connection (setting only the
 * database name), this listener overrides host/port/user/password with
 * the tenant's admin-provided, AES-256-encrypted credentials.
 */
class ConfigureTenantDatabaseConnection
{
    public function handle(TenancyBootstrapped $event): void
    {
        $tenant = $event->tenancy->tenant;

        if (! $tenant->hasDatabaseCredentials()) {
            return;
        }

        $creds = $tenant->getDatabaseCredentials();

        $connectionName = 'tenant';

        Config::set("database.connections.{$connectionName}.host", $creds['host']);
        Config::set("database.connections.{$connectionName}.port", $creds['port']);
        Config::set("database.connections.{$connectionName}.database", $creds['database']);
        Config::set("database.connections.{$connectionName}.username", $creds['username']);
        Config::set("database.connections.{$connectionName}.password", $creds['password']);

        DB::purge($connectionName);
        DB::reconnect($connectionName);
    }
}
