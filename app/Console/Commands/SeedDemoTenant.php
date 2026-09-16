<?php

namespace App\Console\Commands;

use App\Services\DemoTenant\DemoPersonas;
use App\Services\DemoTenant\DemoTenantSeeder;
use App\Services\TenantLimitsService;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate ONE tenant with a realistic, idempotent DEMO dataset (QA only).
 *
 * Safety model:
 *  - Exactly one tenant per run — never "all tenants". {tenant} accepts a
 *    UUID or a domain string.
 *  - Refuses if the tenant does not exist or is not active.
 *  - In production (app()->environment('production')) refuses unless
 *    --allow-production is passed, and even then always shows the full plan
 *    and asks for interactive confirmation — --force only skips the
 *    confirmation prompt, it never skips the production gate and never
 *    deletes/recreates anything.
 *  - --dry-run performs zero writes: it only reads tenant DB counts to
 *    print what WOULD be created, then ends tenancy and returns.
 *  - Idempotency is granular per module (see DemoTenantSeeder) — re-running
 *    after a partial failure fills only the gap, never duplicates, never
 *    touches non-demo rows.
 *
 * Phase A (this version): catalog — units, brands, categories, products.
 * Later phases add inventory/purchases/sales/HRM/recruit/etc. as additional
 * seedX() modules on DemoTenantSeeder plus additional lines in run().
 */
class SeedDemoTenant extends Command
{
    protected $signature = 'prodex:seed-demo-tenant
        {tenant : Tenant UUID or domain}
        {--dry-run : Read-only. Show the plan, write nothing.}
        {--force : Skip the interactive confirmation prompt. Never skips the production gate.}
        {--allow-production : Required in addition to normal confirmation when APP_ENV=production.}';

    protected $description = 'Populate a single tenant with an idempotent, realistic DEMO dataset (QA only).';

    public function handle(): int
    {
        $identifier = (string) $this->argument('tenant');

        $tenant = Tenant::find($identifier)
            ?? Tenant::whereHas('domains', fn ($q) => $q->where('domain', $identifier))->first();

        if (! $tenant) {
            $this->error("Tenant '{$identifier}' not found (looked up by id and by domain). Refusing to run.");

            return self::FAILURE;
        }

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            $this->error("Tenant '{$identifier}' status is '{$tenant->status}', not active. Refusing to run.");

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('APP_ENV=production. Re-run with --allow-production to even see the plan for a production tenant.');

            return self::FAILURE;
        }

        $domain = $tenant->domains()->first()->domain ?? '(no domain)';
        $dbName = $tenant->database()->getName();
        $dryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->info('=== Tenant resolved ===');
        $this->line("ID:        {$tenant->id}");
        $this->line("Domain:    {$domain}");
        $this->line("Database:  {$dbName}");
        $this->line("Status:    {$tenant->status}");

        $exitCode = self::SUCCESS;

        tenancy()->initialize($tenant);
        try {
            $plan = $this->limitsSummary();
            $this->line("Plan:      {$plan}");

            $persona = DemoPersonas::forDomain($domain, $tenant->id);
            $seeder = new DemoTenantSeeder($persona);
            $this->line('Perfil:    '.$seeder->personaLabel().(in_array($domain, ['prueba02', 'pruebapago'], true) ? '' : ' (dominio no reconocido — perfil asignado de forma determinística para pruebas locales)'));

            $this->line('');
            $this->info('=== Estado actual (Fase A + Fase B) ===');
            foreach ($seeder->currentCounts() as $label => $count) {
                $this->line(sprintf('%-14s %d filas totales en la tabla', $label.':', $count));
            }

            $planRows = $seeder->plan();
            $this->line('');
            $this->info('=== Plan (lo que este run crearía) ===');
            $this->table(
                ['Entidad', 'Objetivo demo', 'Ya existen (demo)', 'A crear'],
                collect($planRows)->map(fn ($r, $label) => [$label, $r['target'], $r['existing'], $r['to_create']])->values()->all()
            );

            $this->line('');
            $this->info('=== Potential name collisions / reused records ===');
            $this->comment('units/brands/providers have no code/slug column in this schema — a name match below is REUSED, never duplicated, but cannot be technically told apart from an unrelated normal record with the same name.');
            foreach ($seeder->nameCollisionReport() as $group => $rows) {
                $this->line($group.':');
                foreach ($rows as $r) {
                    $this->line('  - '.$r['label'].' -> '.($r['existing_id'] ? 'existing id='.$r['existing_id'] : 'not found'));
                }
            }

            if ($dryRun) {
                $this->line('');
                $this->comment('--dry-run: sin escrituras. Ningún INSERT/UPDATE/DELETE fue ejecutado.');

                return self::SUCCESS;
            }

            $totalToCreate = array_sum(array_column($planRows, 'to_create'));
            if ($totalToCreate === 0) {
                $this->info('Nada pendiente por crear en Fase A para este tenant — dataset ya completo.');
            }

            if (! $this->option('force')) {
                if (! $this->confirm("¿Continuar y poblar el tenant '{$domain}' ({$dbName})?", false)) {
                    $this->warn('Cancelado por el usuario.');

                    return self::SUCCESS;
                }
            }

            $results = [];
            // Phase A + the simple Phase B catalogs (providers, initial
            // product_warehouse rows) are one plain bulk operation each —
            // safe to wrap in a single transaction per module. Purchases and
            // transfers are NOT wrapped here: each iteration inside those two
            // methods already opens its own transaction (the real
            // PurchasesController/TransferController do), and each has its
            // own try/catch per item — wrapping the whole module in one more
            // transaction would roll back every already-succeeded purchase
            // in the batch the moment a LATER one fails, which is exactly
            // the "no mega-transaction" failure mode this design avoids.
            $transactional = [
                'Unidades' => 'seedUnits',
                'Marcas' => 'seedBrands',
                'Categorías' => 'seedCategories',
                'Productos' => 'seedProducts',
                'Proveedores' => 'seedProviders',
                'Stock inicial (product_warehouse)' => 'seedInitialStock',
                'Clientes' => 'seedClients',
            ];
            $selfContained = [
                'Compras' => 'seedPurchases',
                'Transferencias' => 'seedTransfers',
                'Ventas' => 'seedSales',
                'Cotizaciones' => 'seedQuotations',
                'Promociones' => 'seedPromotions',
            ];

            foreach ($transactional as $label => $method) {
                try {
                    // Plain function + explicit by-ref use: an `fn()` arrow
                    // function captures $results BY VALUE at definition time,
                    // so an assignment inside it never reaches the outer
                    // array — the module's writes would commit fine but the
                    // summary table would silently render empty. Verified
                    // against demo01 before this fix.
                    DB::transaction(function () use (&$results, $label, $seeder, $method) {
                        $results[$label] = $seeder->{$method}();
                    });
                } catch (\Throwable $e) {
                    $this->error("Módulo '{$label}' falló: {$e->getMessage()}");
                    $results[$label] = ['created' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 1];
                    $exitCode = self::FAILURE;
                }
            }

            foreach ($selfContained as $label => $method) {
                try {
                    $results[$label] = $seeder->{$method}();
                    foreach ($results[$label]['errors'] ?? [] as $err) {
                        $this->error($err);
                        $exitCode = self::FAILURE;
                    }
                } catch (\Throwable $e) {
                    $this->error("Módulo '{$label}' falló: {$e->getMessage()}");
                    $results[$label] = ['created' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 1];
                    $exitCode = self::FAILURE;
                }
            }

            $this->line('');
            $this->comment('Ajustes de inventario: OMITIDOS en Fase B. AdjustmentController::store() exige inventory_location_id de forma incondicional (no solo en modo location-primary); este tenant no tiene ninguna inventory_location provisionada. Provisionarla es una decisión de infraestructura fuera del alcance de un seeder de datos demo.');

            $this->line('');
            $this->comment('Reservas: OMITIDAS en Fase C. El módulo de Bookings existe y es funcional (BookingController, tabla bookings), pero sus rutas están protegidas por tenant.feature:bookings y el plan activo de este tenant ("Emprendedor") no incluye esa característica — TenantLimitsService->hasFeature(\'bookings\') es false. Sembrar reservas de todas formas representaría a demo01 con una capacidad de plan que no tiene. Ningún producto DEMO es is_service, tampoco.');
            $results['Reservas'] = ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];

            $this->line('');
            $this->info('=== DEMO DATA SUMMARY ===');
            $this->table(
                ['Entidad', 'Created', 'Existing/Reused', 'Skipped', 'Failed'],
                collect($results)->map(fn ($r, $label) => [$label, $r['created'], $r['existing'], $r['skipped'], $r['failed']])->values()->all()
            );
        } finally {
            tenancy()->end();
        }

        return $exitCode;
    }

    private function limitsSummary(): string
    {
        $plan = app(TenantLimitsService::class)->getActivePlan();

        return $plan ? ($plan->name.' ('.$plan->slug.')') : 'sin suscripción activa resuelta';
    }
}
