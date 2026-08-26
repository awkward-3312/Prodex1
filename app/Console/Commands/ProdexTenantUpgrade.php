<?php

namespace App\Console\Commands;

use App\Services\TenantSchemaHealthService;
use App\Tenant;
use Illuminate\Console\Command;
use Throwable;

class ProdexTenantUpgrade extends Command
{
    protected $signature = 'prodex:tenant-upgrade {--tenants=* : Tenant IDs to upgrade. Defaults to all tenants.}';

    protected $description = 'Safely apply only controlled modern tenant migrations.';

    public function handle(TenantSchemaHealthService $schemaHealth): int
    {
        $tenantIds = array_filter((array) $this->option('tenants'));
        $query = Tenant::query()->orderBy('id');

        if (! empty($tenantIds)) {
            $query->whereIn('id', $tenantIds);
        }

        $tenants = $query->get();
        $summary = ['tenants' => $tenants->count(), 'ok' => 0, 'failed' => 0, 'warnings' => 0];

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found for upgrade.');
            return ! empty($tenantIds) ? self::FAILURE : self::SUCCESS;
        }

        $controlledMigrations = array_values(array_unique(array_merge(
            TenantSchemaHealthService::CONTROLLED_MIGRATIONS,
            [
                'database/migrations/tenant/2026_08_22_230000_make_users_phone_nullable.php',
                'database/migrations/tenant/2026_08_23_150000_link_transfer_defects_to_damages.php',
                'database/migrations/tenant/2026_08_25_181500_add_native_location_context_to_cash_registers.php',
            ]
        )));

        foreach ($tenants as $tenant) {
            $creds = $tenant->getEffectiveDatabaseCredentials();
            $this->newLine();
            $this->info("Tenant {$tenant->id} | DB: ".($creds['database'] ?? 'unknown'));

            try {
                tenancy()->initialize($tenant);

                foreach ($controlledMigrations as $relativePath) {
                    $path = base_path($relativePath);
                    $migration = basename($relativePath, '.php');

                    if (! is_file($path)) {
                        $summary['warnings']++;
                        $this->warn("  {$migration}: file not found");
                        continue;
                    }

                    $exitCode = $this->callSilent('migrate', [
                        '--database' => 'tenant',
                        '--path' => $path,
                        '--realpath' => true,
                        '--force' => true,
                    ]);

                    if ($exitCode === self::SUCCESS) {
                        $this->line("  {$migration}: ok/skipped if already applied");
                    } else {
                        $summary['warnings']++;
                        $this->warn("  {$migration}: migrate returned exit code {$exitCode}");
                    }
                }

                $after = $schemaHealth->missingRequirements();
                if (empty($after)) {
                    $summary['ok']++;
                    $this->info('  Result: healthy');
                } else {
                    $summary['warnings']++;
                    $this->warn('  Result: still requires update');
                    foreach (array_slice($after, 0, 10) as $missing) {
                        $this->line("    - {$missing}");
                    }
                    if (count($after) > 10) {
                        $this->line('    - +'.(count($after) - 10).' more');
                    }
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->error('  Failed: '.$e->getMessage());
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->newLine();
        $this->info("Summary: {$summary['tenants']} tenants, {$summary['ok']} healthy, {$summary['warnings']} warnings, {$summary['failed']} failures.");

        return ($summary['warnings'] > 0 || $summary['failed'] > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
