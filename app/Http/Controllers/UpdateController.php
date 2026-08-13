<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureNotUpdating;
use App\Models\Permission;
use App\Models\Role;
use App\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    /** Statuses excluded from the migration target list. */
    private const SKIP_STATUSES = [
        Tenant::STATUS_PENDING,
        Tenant::STATUS_PROVISIONING,
        Tenant::STATUS_FAILED,
    ];

    /** GET /update — Render the progress UI with inventory of update targets. */
    public function show()
    {
        $this->assertSuperAdmin();
        $targets = $this->targets();

        return view('update.index', [
            'targets'     => $targets,
            'lockActive'  => $this->isLocked(),
        ]);
    }

    /** POST /update/start — Engage maintenance mode and return the target list. */
    public function start(Request $request): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->engageMaintenance();

        return response()->json([
            'ok'      => true,
            'targets' => $this->targets(),
        ]);
    }

    /** POST /update/run-central — Run central migrations. */
    public function runCentral(): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->raiseLimits();

        try {
            Artisan::call('config:clear');
            Artisan::call('migrate', [
                '--database' => 'central',
                '--force'    => true,
            ]);
            $output = trim(Artisan::output());
            Log::info('Platform update: central migrated.', ['output' => $output]);

            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\CentralLanguagesSeeder',
                '--force' => true,
            ]);
            Log::info('Platform update: central languages seeded.');

            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Central\\PlanLimitsSyncSeeder',
                '--force' => true,
            ]);
            Log::info('Platform update: plan limits synced.');

            return response()->json([
                'ok'     => true,
                'output' => $output ?: 'Nothing to migrate.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Platform update: central migration failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** POST /update/run-tenant/{tenantId} — Run migrations + seeders for one tenant. */
    public function runTenant(string $tenantId): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->raiseLimits();

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return response()->json(['ok' => false, 'error' => 'Tenant not found.'], 404);
        }

        if (in_array($tenant->status, self::SKIP_STATUSES, true)) {
            return response()->json([
                'ok'      => false,
                'skipped' => true,
                'error'   => "Skipped (status: {$tenant->status}).",
            ]);
        }

        $output = '';

        try {
            $tenant->run(function () use (&$output) {
                Artisan::call('migrate', [
                    '--path'     => [database_path('migrations/tenant')],
                    '--realpath' => true,
                    '--force'    => true,
                ]);
                $output = trim(Artisan::output());

                $permissions = [
                    1  => 'webhooks_view',
                    2  => 'webhooks_add',
                    3  => 'webhooks_edit',
                    4  => 'webhooks_delete',
                    5  => 'sales_3d_dashboard',
                    6  => 'batch_view',
                    7  => 'batch_manage',
                    8  => 'batch_writeoff',
                    9  => 'batch_force_override',
                    10 => 'expiry_report',
                    11 => 'Batch_Register_Report',

                    // Recruit module
                    12 => 'recruit_job',
                    13 => 'recruit_category',
                    14 => 'recruit_candidate',
                    15 => 'recruit_application',
                    16 => 'recruit_interview',

                    // Meeting module
                    17 => 'meeting',
                    18 => 'meeting_attendance',
                    19 => 'meeting_report',

                    // Marketing module
                    20 => 'marketing_dashboard',
                    21 => 'marketing_campaigns',
                    22 => 'marketing_segments',
                    23 => 'marketing_templates',
                    24 => 'marketing_reports',
                    25 => 'marketing_settings',

                    // Real Estate module
                    26 => 'realestate_properties',
                    27 => 'realestate_categories',
                    28 => 'realestate_inquiries',

                    // Serial / IMEI tracking module
                    29 => 'serial_numbers',
                    30 => 'serial_numbers_report',

                    // Kitchen display module
                    31 => 'kitchen_display_view',
                    32 => 'kitchen_display_manage',
                    33 => 'support',

                    // ZATCA e-invoicing (Fatoora)
                    34 => 'zatca_settings',

                    // Promotions & discounts module
                    35 => 'promotion',
                    36 => 'whatsapp_settings',
                    37 => 'whatsapp_templates',
                    38 => 'whatsapp_logs',

                    // Shopify integration module
                    39 => 'shopify_settings',

                ];

                foreach ($permissions as $permission_slug) {
                    Permission::firstOrCreate(['name' => $permission_slug]);
                }

                $ownerRole = Role::find(1);
                if ($ownerRole) {
                    $ownerRole->permissions()->sync(
                        Permission::pluck('id')->toArray()
                    );
                }

                $this->seedTranslationsChunked();

                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\StoreSettingSeeder',
                    '--force' => true,
                ]);

                $this->clearTenantCache();
            });

            Log::info("Platform update: tenant {$tenantId} migrated.");

            return response()->json([
                'ok'     => true,
                'output' => $output ?: 'Nothing to migrate.',
            ]);
        } catch (\Throwable $e) {
            Log::error("Platform update: tenant {$tenantId} failed.", [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** POST /update/finish — Clear central caches and disengage maintenance. */
    public function finish(): JsonResponse
    {
        $this->assertSuperAdmin();
        try {
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');
            // Application cache is shared by central + all tenants (tenant
            // entries are key-prefixed onto the same store, see
            // PrefixCacheTenancyBootstrapper), so one flush here clears
            // tenant-side caches on every driver — not just the file driver
            // handled by clearTenantCache() during runTenant().
            Artisan::call('cache:clear');
        } catch (\Throwable $e) {
            Log::warning('Platform update: cache clear failed.', ['error' => $e->getMessage()]);
        }

        $this->disengageMaintenance();

        return response()->json(['ok' => true]);
    }

    /** POST /update/abort — Emergency release of the maintenance lock. */
    public function abort(): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->disengageMaintenance();
        Log::warning('Platform update: maintenance lock manually released.');
        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Build the ordered list of update targets (central + eligible tenants). */
    private function targets(): array
    {
        $targets = [[
            'key'    => 'central',
            'id'     => 'central',
            'label'  => 'Central database',
            'type'   => 'central',
            'status' => 'ready',
        ]];

        $tenants = Tenant::whereNotIn('status', self::SKIP_STATUSES)
            ->with('domains')
            ->orderBy('created_at')
            ->get();

        foreach ($tenants as $tenant) {
            $domain = optional($tenant->domains->first())->domain;
            $targets[] = [
                'key'    => 'tenant:' . $tenant->id,
                'id'     => $tenant->id,
                'label'  => $domain ?: $tenant->id,
                'type'   => 'tenant',
                'status' => 'ready',
                'meta'   => [
                    'id'     => $tenant->id,
                    'domain' => $domain,
                    'state'  => $tenant->status,
                ],
            ];
        }

        return $targets;
    }

    private function assertSuperAdmin(): void
    {
        $user = Auth::guard('central')->user();
        if (! $user || ! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            abort(403, 'Only super admins can run platform updates.');
        }
    }

    private function engageMaintenance(): void
    {
        $path = storage_path('app/' . EnsureNotUpdating::LOCK_FILE);
        @file_put_contents($path, json_encode([
            'started_at' => now()->toIso8601String(),
            'by'         => optional(Auth::guard('central')->user())->email,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function disengageMaintenance(): void
    {
        $path = storage_path('app/' . EnsureNotUpdating::LOCK_FILE);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function isLocked(): bool
    {
        return file_exists(storage_path('app/' . EnsureNotUpdating::LOCK_FILE));
    }

    private function raiseLimits(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1G');
    }

    /** Seed translations one language file at a time to keep memory low. */
    private function seedTranslationsChunked(): void
    {
        \DB::disableQueryLog();

        $dir = database_path('seeders/translations');
        if (! is_dir($dir)) {
            return;
        }

        $files = \Illuminate\Support\Facades\File::files($dir);

        foreach ($files as $file) {
            $locale       = pathinfo($file, PATHINFO_FILENAME);
            $translations = require $file;
            $isDefault    = $locale === 'en' ? 1 : 0;
            $now          = now();

            $batch = [];
            foreach ($translations as $key => $value) {
                $batch[] = [
                    'locale'     => $locale,
                    'key'        => $key,
                    'value'      => $value,
                    'is_default' => $isDefault,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 500) {
                    \DB::table('translations')->upsert($batch, ['locale', 'key'], ['value', 'is_default', 'updated_at']);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                \DB::table('translations')->upsert($batch, ['locale', 'key'], ['value', 'is_default', 'updated_at']);
            }

            unset($translations, $batch);
        }
    }

    /** Clear only the current tenant's file cache and compiled views. */
    private function clearTenantCache(): void
    {
        $cachePath = storage_path('framework/cache/data');
        if (is_dir($cachePath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cachePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
            }
        }

        $viewsPath = storage_path('framework/views');
        if (is_dir($viewsPath)) {
            foreach (glob($viewsPath . '/*.php') as $view) {
                @unlink($view);
            }
        }
    }
}
