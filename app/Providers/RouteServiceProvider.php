<?php

namespace App\Providers;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;

class RouteServiceProvider extends ServiceProvider
{
    /** @var string */
    protected $namespace = 'App\Http\Controllers';

    public const HOME = '/';

    public function boot()
    {
        Route::model('admin', CentralUser::class);
        Route::model('category', \App\Models\Central\KbCategory::class);
        Route::model('article', \App\Models\Central\KbArticle::class);

        parent::boot();
    }

    /**
     * Central vs tenant: when not installed, always load central routes (setup) so setup works on any host.
     * After install, load central on central domains and tenant routes on tenant domains.
     */
    public function map()
    {
        if (app()->runningInConsole()) {
            $this->mapCentralRoutes();
            return;
        }

        if (! file_exists(base_path('storage/app/public/installed'))) {
            $this->mapCentralRoutes();
            return;
        }

        $this->mapUniversalRoutes();

        $host = request()->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        if (in_array($host, $centralDomains, true)) {
            $this->mapCentralRoutes();
        } else {
            $this->mapTenantRoutes();
        }
    }

    protected function mapUniversalRoutes(): void
    {
        // Reserved for callbacks that must be available without tenant context.
    }

    protected function mapCentralRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/central.php'));

        Route::namespace($this->namespace)
            ->group(base_path('routes/central_bank_accounts.php'));
    }

    protected function mapTenantRoutes(): void
    {
        $tenancy = [
            \App\Http\Middleware\RedirectSetupToCentral::class,
            PreventAccessFromCentralDomains::class,
            InitializeTenancyByDomainOrSubdomain::class,
            'tenant.active',
        ];

        // Must be registered before tenant_web.php because that file ends in the
        // authenticated SPA catch-all. Phone-camera QR links need this exact route.
        Route::middleware(array_merge(['web'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_transfer_logistics_web.php'));

        Route::middleware(array_merge(['web'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_web.php'));

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_api.php'));

        // Transfer logistics is isolated from the legacy transfer resource so the
        // receiving workflow can evolve without destabilizing the historical API.
        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_transfer_logistics.php'));

        // Attendance integrations live in a small isolated route file so new
        // biometric/import APIs do not increase the risk of editing the large
        // historical tenant_api.php file.
        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_attendance_integrations.php'));

        // Small, isolated route overrides that must be registered after the legacy
        // tenant API routes. This keeps SAR-specific rendering out of the large
        // historical route/controller files while preserving non-SAR fallbacks.
        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_sar_overrides.php'));

        Route::middleware(array_merge(['web'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/portal.php'));
    }
}
