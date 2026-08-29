<?php

namespace App\Jobs;

use App\Models\Central\PendingRegistration;
use App\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;

class ProvisionTenantWorkspace implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 2000;

    /**
     * Whether the tenant database already contained real business data
     * (users/sales) BEFORE this run started. When true, the destructive
     * `db:wipe` step is skipped no matter what — this protects admin-provided
     * (shared-hosting) databases and any tenant that still has live data.
     */
    protected bool $tenantDbHadDataBeforeProvision = false;

    public function __construct(
        protected string $tenantId,
        protected bool $forceReprovision = false,
        /**
         * Allow the destructive `db:wipe` (drop every tenant table) as part of
         * re-provisioning. Only ever pass true for tenants that are being
         * repaired from a failed/partial provision — never for an active tenant.
         * A brand-new (empty) tenant database is wiped regardless of this flag.
         */
        protected bool $allowDestructiveWipe = false,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        set_time_limit(2000);
        ignore_user_abort(true);

        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            Log::error("ProvisionTenantWorkspace: Tenant {$this->tenantId} not found.");
            return;
        }

        if ($tenant->status === 'active' && ! $this->forceReprovision) {
            Log::info("ProvisionTenantWorkspace: Tenant {$this->tenantId} already active, skipping.");
            return;
        }

        // A confirmed FAILED provision legitimately needs a clean slate on retry.
        // We intentionally do NOT auto-enable for PROVISIONING: callers such as
        // TenantController::reprovision() flip the status to "provisioning"
        // *before* dispatching, so that state is ambiguous and must rely on the
        // explicit $allowDestructiveWipe flag the caller passed in.
        if ($tenant->status === Tenant::STATUS_FAILED) {
            $this->allowDestructiveWipe = true;
        }

        try {
            $tenant->update(['status' => 'provisioning']);

            Log::info("ProvisionTenantWorkspace: Starting provisioning for {$this->tenantId}");

            $dbCreated = $this->createDatabase($tenant);
            Log::info("ProvisionTenantWorkspace: Database created/verified for {$this->tenantId}");

            $this->runMigrations($tenant);
            Log::info("ProvisionTenantWorkspace: Migrations complete for {$this->tenantId}");

            $seeded = $this->seedDatabase($tenant, $dbCreated);
            Log::info("ProvisionTenantWorkspace: Seeding complete for {$this->tenantId}");

            $this->createAdminUser($tenant);
            Log::info("ProvisionTenantWorkspace: Admin user created for {$this->tenantId}");

            if ($seeded) {
                $this->seedDemoData($tenant);
            }

            $this->installPassport($tenant);
            Log::info("ProvisionTenantWorkspace: Passport installed for {$this->tenantId}");

            $this->configureCompany($tenant);
            Log::info("ProvisionTenantWorkspace: Company configured for {$this->tenantId}");

            $this->copyDefaultImages($tenant);
            Log::info("ProvisionTenantWorkspace: Default images copied for {$this->tenantId}");

            $tenant->update(['status' => 'active']);

            $this->markRegistrationCompleted($tenant);

            Log::info("ProvisionTenantWorkspace: Tenant {$this->tenantId} provisioned successfully.");
        } catch (\Throwable $e) {
            Log::error("ProvisionTenantWorkspace: Failed for tenant {$this->tenantId}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            $tenant->provisioning_error = $e->getMessage();
            $tenant->status = 'failed';
            $tenant->save();

            // Capture provisioning failure for super admin monitoring.
            // Wrapped because capture() already swallows errors, but we keep a belt-and-braces guard.
            try {
                \App\Models\Central\SystemLog::capture([
                    'tenant_id'       => $tenant->id,
                    'type'            => \App\Models\Central\SystemLog::TYPE_PROVISIONING,
                    'severity'        => \App\Models\Central\SystemLog::SEVERITY_CRITICAL,
                    'message'         => 'Tenant provisioning failed: ' . $e->getMessage(),
                    'context'         => [
                        'exception' => get_class($e),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
                    ],
                    'suggested_cause' => 'Check the database credentials, server connectivity, and tenant-side migrations. Use "Re-check" or re-run provisioning from the tenant detail page.',
                    'source'          => 'job.provision_tenant_workspace',
                ]);
            } catch (\Throwable $ignored) {}
        }
    }

    protected function createDatabase(Tenant $tenant): bool
    {
        // When admin-provided DB credentials exist, the database was already
        // created externally — just ensure tenancy_db_name is set.
        if ($tenant->hasDatabaseCredentials()) {
            $dbName = $tenant->tenancy_db_name;
            Log::info("ProvisionTenantWorkspace: Using admin-provided DB '{$dbName}' for tenant {$tenant->id}");

            // NEVER assume an admin-provided database is empty. If it already
            // holds business data, mark it so the destructive wipe is skipped
            // and treat it as "not fresh" for seeding.
            $this->tenantDbHadDataBeforeProvision = $this->tenantDatabaseHasBusinessData($tenant);

            return ! $this->tenantDbHadDataBeforeProvision;
        }

        $dbName = config('tenancy.database.prefix') . $tenant->id . config('tenancy.database.suffix');

        $charset   = config('database.connections.central.charset', 'utf8mb4');
        $collation = config('database.connections.central.collation', 'utf8mb4_unicode_ci');

        $exists = DB::connection('central')
            ->select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?", [$dbName]);

        $freshlyCreated = empty($exists);

        DB::connection('central')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}"
        );

        // Make sure the tenant knows its database name before any run() calls
        $tenant->setInternal('tenancy_db_name', $dbName);
        $tenant->save();

        // A pre-existing auto-provisioned database that already holds business
        // data must also be protected from the wipe (e.g. a re-provision that
        // races with an in-flight one, or a mislabelled tenant status).
        if (! $freshlyCreated) {
            $this->tenantDbHadDataBeforeProvision = $this->tenantDatabaseHasBusinessData($tenant);
        }

        // Verify the tenant can resolve its database
        Log::info("ProvisionTenantWorkspace: DB name set to '{$dbName}' for tenant {$tenant->id}, freshly created: " . ($freshlyCreated ? 'yes' : 'no') . ', has business data: ' . ($this->tenantDbHadDataBeforeProvision ? 'yes' : 'no'));

        return $freshlyCreated;
    }

    /**
     * True when the tenant database already contains real business data.
     * A bare `migrations` table (left over from a failed provision) does NOT
     * count — those are safe to wipe; `users`/`sales` rows are not.
     */
    protected function tenantDatabaseHasBusinessData(Tenant $tenant): bool
    {
        $hasData = false;

        try {
            $tenant->run(function () use (&$hasData) {
                foreach (['users', 'sales', 'products'] as $table) {
                    if (Schema::hasTable($table) && DB::table($table)->limit(1)->exists()) {
                        $hasData = true;
                        return;
                    }
                }
            });
        } catch (\Throwable $e) {
            // Database not reachable / does not exist yet → nothing to protect.
            Log::warning("ProvisionTenantWorkspace: could not inspect tenant {$tenant->id} database for existing data: {$e->getMessage()}");
        }

        return $hasData;
    }

    protected function runMigrations(Tenant $tenant): void
    {
        $allowWipe = $this->allowDestructiveWipe && ! $this->tenantDbHadDataBeforeProvision;

        $tenant->run(function () use ($allowWipe, $tenant) {
            // A brand-new tenant DB (no migrations table yet) is always safe to
            // wipe. Otherwise only wipe when explicitly allowed for a
            // failed/partial provision AND the DB holds no real business data.
            $isFreshDatabase = ! Schema::hasTable('migrations');

            if ($isFreshDatabase || $allowWipe) {
                Artisan::call('db:wipe', ['--force' => true]);
            } else {
                Log::warning(
                    "ProvisionTenantWorkspace: skipping db:wipe for tenant {$tenant->id} "
                    . '(database is not empty and a destructive wipe is not permitted here); '
                    . 'running migrate on top of the existing schema instead.'
                );
            }

            Artisan::call('migrate', [
                '--path'     => [database_path('migrations/tenant')],
                '--realpath' => true,
                '--force'    => true,
            ]);
        });
    }

    protected function seedDatabase(Tenant $tenant, bool $freshDb = true): bool
    {
        if (! $freshDb) {
            $hasData = false;
            $tenant->run(function () use (&$hasData) {
                try {
                    $hasData = DB::table('roles')->exists();
                } catch (\Throwable) {
                    $hasData = false;
                }
            });

            if ($hasData) {
                Log::info("ProvisionTenantWorkspace: Database already seeded for tenant {$tenant->id}, skipping.");
                return false;
            }
        }

        $tenant->run(function () {
            Artisan::call('db:seed', [
                '--class' => 'DatabaseSeeder',
                '--force' => true,
            ]);
        });

        return true;
    }

    /**
     * Populate the tenant with sample demo data (catalog, customers,
     * suppliers and transactions) when the super admin has enabled the
     * Demo Tenant Generator in General Settings. Only runs once, right
     * after the base seed, and never blocks provisioning on failure.
     */
    protected function seedDemoData(Tenant $tenant): void
    {
        try {
            if (! \App\Models\Central\GeneralSetting::demoDataEnabled()) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning("ProvisionTenantWorkspace: Could not resolve demo-data setting for tenant {$tenant->id}: {$e->getMessage()}");
            return;
        }

        try {
            $tenant->run(function () {
                Artisan::call('db:seed', [
                    '--class' => 'DemoDataSeeder',
                    '--force' => true,
                ]);
            });
            Log::info("ProvisionTenantWorkspace: Demo data seeded for tenant {$tenant->id}");
        } catch (\Throwable $e) {
            // Demo data is non-essential — log and continue so the tenant
            // still provisions successfully.
            Log::error("ProvisionTenantWorkspace: Demo data seeding failed for tenant {$tenant->id}: {$e->getMessage()}");
        }
    }

    protected function createAdminUser(Tenant $tenant): void
    {
        $email        = $tenant->admin_email ?? null;
        $passwordHash = $tenant->admin_password_hash ?? null;

        if (! $email || ! $passwordHash) {
            Log::warning("ProvisionTenantWorkspace: No admin credentials in tenant data for {$tenant->id}.");
            return;
        }

        $tenant->run(function () use ($email, $passwordHash) {
            $user = DB::table('users')->where('id', 1)->first();

            if ($user) {
                DB::table('users')->where('id', 1)->update([
                    'email'    => $email,
                    'password' => $passwordHash,
                ]);
            } else {
                DB::table('users')->insert([
                    'id'        => 1,
                    'firstname' => 'Admin',
                    'lastname'  => '',
                    'username'  => $email,
                    'email'     => $email,
                    'password'  => $passwordHash,
                    'phone'     => '',
                    'role_id'   => 1,
                    'statut'    => 1,
                    'is_all_warehouses' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $tenant->admin_password_hash = null;
        $tenant->save();
    }

    protected function installPassport(Tenant $tenant): void
    {
        $tenant->run(function () {
            try {
                // Some tenant migrations already create Passport's tables.
                // Only run the vendor migrations when the tables are absent.
                if (! Schema::hasTable('oauth_clients')) {
                    Artisan::call('migrate', [
                        '--path'  => 'vendor/laravel/passport/database/migrations',
                        '--force' => true,
                    ]);
                }

                $clientRepository = app(ClientRepository::class);
                $appUrl = config('app.url') ?: 'http://localhost';

                if (! DB::table('oauth_clients')->where('personal_access_client', 1)->exists()) {
                    $clientRepository->createPersonalAccessClient(
                        null,
                        'Prodex Personal Access Client',
                        $appUrl
                    );
                }

                if (! DB::table('oauth_clients')->where('password_client', 1)->exists()) {
                    $clientRepository->createPasswordGrantClient(
                        null,
                        'Prodex Password Grant Client',
                        $appUrl
                    );
                }

            } catch (\Throwable $e) {
                Log::warning("ProvisionTenantWorkspace: Passport install failed: {$e->getMessage()}");
            }
        });
    }

    protected function configureCompany(Tenant $tenant): void
    {
        $companyName = $tenant->company_name ?? null;

        if (! $companyName) {
            return;
        }

        $tenant->run(function () use ($companyName) {
            $exists = DB::table('companies')->where('id', 1)->exists();

            if ($exists) {
                DB::table('companies')->where('id', 1)->update([
                    'name' => $companyName,
                ]);
            } else {
                DB::table('companies')->insert([
                    'id'         => 1,
                    'name'       => $companyName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    protected function copyDefaultImages(Tenant $tenant): void
    {
        $source = public_path('images/tenant-default');
        $destination = public_path('images/tenants/' . $tenant->id);

        if (! File::isDirectory($source)) {
            Log::warning("ProvisionTenantWorkspace: Default images template not found at {$source}");
            return;
        }

        File::copyDirectory($source, $destination);

        // If the tenant-default template has no PWA icons of its own, seed the
        // tenant's pwa/ folder from the shared /pwa_images defaults so the copy
        // still ships a valid 192/512 icon pair before per-tenant generation.
        $this->seedDefaultPwaIcons($source, $destination);

        // Overlay any super-admin-configured tenant default logo / favicon
        // on top of the freshly-copied defaults. We keep the destination
        // filename ("logo-default.png" / "favicon.ico") so the tenant
        // settings row needs no per-tenant customization.
        $this->applyConfiguredTenantBranding($destination);

        // Generate this tenant's PWA icons from the configured branding so the
        // installed app (POS / store / portal / customer display) uses the
        // tenant's own logo instead of the shared platform default.
        $this->generatePwaIcons($tenant, $destination);
    }

    /**
     * When the tenant-default template ships no PWA icons (images/tenant-default/pwa
     * missing), fall back to the shared /pwa_images icons so the freshly-copied
     * tenant folder always has a valid 192/512 pair. If the template already
     * provides pwa/ this is a no-op.
     */
    protected function seedDefaultPwaIcons(string $source, string $destination): void
    {
        $templatePwa = $source . DIRECTORY_SEPARATOR . 'pwa';
        if (File::isDirectory($templatePwa)) {
            return;
        }

        $fallback = public_path('pwa_images');
        if (! File::isDirectory($fallback)) {
            Log::warning("ProvisionTenantWorkspace: PWA icon fallback not found at {$fallback}");
            return;
        }

        $destPwa = $destination . DIRECTORY_SEPARATOR . 'pwa';
        if (! File::isDirectory($destPwa)) {
            File::makeDirectory($destPwa, 0755, true);
        }

        foreach (['pwa-icon-192.png', 'pwa-icon-512.png'] as $icon) {
            $src = $fallback . DIRECTORY_SEPARATOR . $icon;
            if (is_file($src)) {
                @copy($src, $destPwa . DIRECTORY_SEPARATOR . $icon);
            }
        }
    }

    /**
     * If the super admin configured a default tenant logo/favicon in the
     * Tenant Settings tab, overwrite the freshly-copied default files so
     * NEW tenants ship with the configured branding from minute zero.
     * Existing tenants are not touched because this only runs at
     * provisioning time.
     */
    protected function applyConfiguredTenantBranding(string $destination): void
    {
        try {
            $settings = \App\Models\Central\GeneralSetting::instance();
        } catch (\Throwable) {
            return;
        }

        $settingsDir = $destination . DIRECTORY_SEPARATOR . 'settings';
        if (! File::isDirectory($settingsDir)) {
            File::makeDirectory($settingsDir, 0755, true);
        }

        if ($settings->tenant_logo_path) {
            $src = public_path($settings->tenant_logo_path);
            if (is_file($src)) {
                @copy($src, $settingsDir . DIRECTORY_SEPARATOR . 'logo-default.png');
            }
        }

        if ($settings->tenant_favicon_path) {
            $src = public_path($settings->tenant_favicon_path);
            if (is_file($src)) {
                @copy($src, $settingsDir . DIRECTORY_SEPARATOR . 'favicon.ico');
            }
        }
    }

    /**
     * Generate the tenant's PWA icons (192 / 512) from the super-admin's
     * configured Tenant-Settings logo. The icons are written to
     * images/tenants/{id}/pwa/ and served by PwaManifestController. If no logo
     * is configured the tenant keeps the shared default icons (pwa_icon_url()
     * falls back to /pwa_images).
     */
    protected function generatePwaIcons(Tenant $tenant, string $destination): void
    {
        try {
            $settings = \App\Models\Central\GeneralSetting::instance();
        } catch (\Throwable) {
            return;
        }

        // Prefer the super-admin's configured tenant default logo; fall back to
        // the per-tenant logo just copied into the settings folder.
        $source = $settings->tenant_logo_path ? public_path($settings->tenant_logo_path) : null;
        if (! $source || ! is_file($source)) {
            $copiedLogo = $destination . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'logo-default.png';
            $source = is_file($copiedLogo) ? $copiedLogo : null;
        }

        try {
            \App\Services\PwaIconService::generate($source, $destination);
        } catch (\Throwable $e) {
            Log::warning("ProvisionTenantWorkspace: PWA icon generation failed for tenant {$tenant->id}: {$e->getMessage()}");
        }
    }

    protected function markRegistrationCompleted(Tenant $tenant): void
    {
        try {
            PendingRegistration::where('tenant_id', $tenant->id)
                ->whereIn('status', [
                    PendingRegistration::STATUS_PROVISIONING,
                    PendingRegistration::STATUS_PAID,
                ])
                ->update(['status' => PendingRegistration::STATUS_COMPLETED]);
        } catch (\Throwable $e) {
            Log::warning("ProvisionTenantWorkspace: Could not mark registration completed: {$e->getMessage()}");
        }
    }
}