<?php

namespace App\Providers;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
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
            // Runs only after tenancy has selected the tenant database. It is a no-op
            // for guests and web pages, but blocks authenticated API users from
            // operating against warehouses outside their assigned scope.
            \App\Http\Middleware\EnforceWarehouseScope::class,
        ];

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

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_organization.php'));

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_pos_context.php'));

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_transfer_logistics.php'));

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_attendance_integrations.php'));

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_sar_overrides.php'));

        Route::middleware(array_merge(['web'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/portal.php'));
    }
}
