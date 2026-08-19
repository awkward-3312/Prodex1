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
        // Flutterwave v3 uses hosted checkout with redirect_url — no universal
        // callback route needed; the redirect goes to the tenant's own success page.
    }

    protected function mapCentralRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/central.php'));

        // Isolated extension for the multiple-bank-account manager. Keeping this
        // separate avoids making the already large central route file harder to
        // maintain. Authentication and settings permission are applied inside it.
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

        Route::middleware(array_merge(['web'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_web.php'));

        Route::prefix('api')
            ->middleware(array_merge(['api'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/tenant_api.php'));

        Route::middleware(array_merge(['web'], $tenancy))
            ->namespace($this->namespace)
            ->group(base_path('routes/portal.php'));
    }
}
