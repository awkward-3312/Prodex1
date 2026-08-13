<?php

declare(strict_types=1);

/**
 * Central (SaaS) tenancy configuration.
 * Central DB: tenants, domains, plans, tenant_subscriptions, billing.
 * Tenant DBs: all Stocky business data.
 */

use Stancl\Tenancy\Database\Models\Domain;
use App\Tenant;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => Stancl\Tenancy\UUIDGenerator::class,
    'domain_model' => Domain::class,

    // Central domains are derived from APP_URL (set automatically during setup).
    // Both the apex and www variants are registered so a www/apex mismatch never
    // falls through to tenant identification.
    // Override with CENTRAL_DOMAINS env var (comma-separated) if needed.
    'central_domains' => env('CENTRAL_DOMAINS')
        ? array_values(array_filter(array_map('trim', explode(',', env('CENTRAL_DOMAINS')))))
        : (function () {
            $host = parse_url(env('APP_URL', ''), PHP_URL_HOST) ?: '';
            $apex = preg_replace('/^www\./i', '', $host);

            return array_values(array_unique(array_filter([
                $host,
                $apex,
                $apex !== '' && ! in_array($apex, ['127.0.0.1', 'localhost'], true) ? 'www.' . $apex : null,
                '127.0.0.1',
                'localhost',
            ])));
        })(),

    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        App\Tenancy\PrefixCacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => null,
        'prefix' => 'tenant',
        'suffix' => '',
        'managers' => [
            'sqlite' => Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
            'mysql' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
        ],
    ],

    'cache' => ['tag_base' => 'tenant'],
    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => ['local', 'public'],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => true,
    ],
    'redis' => ['prefix_base' => 'tenant', 'prefixed_connections' => []],
    'features' => [],
    'routes' => true,

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],
    'seeder_parameters' => ['--class' => 'DatabaseSeeder'],

    'trial_days' => (int) env('TENANCY_TRIAL_DAYS', 14),

    'expiry_warning_days' => (int) env('TENANCY_EXPIRY_WARNING_DAYS', 7),
];
