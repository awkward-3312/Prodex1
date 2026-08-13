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

        // Not installed: load central routes only (setup, landing). No tenant logic, no tenants table — same as before tenancy.
        if (! file_exists(base_path('storage/app/public/installed'))) {
            $this->mapCentralRoutes();
            return;
        }

        // Universal routes — gateway callbacks that may arrive on external domains (e.g. ngrok)
        $this->mapUniversalRoutes();

        $host = request()->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        if (in_array($host, $centralDomains, true)) {
            $this->mapCentralRoutes();
        } else {
            // Any non-central host is treated as a tenant host. We deliberately
            // do NOT gate this on a DB lookup (isTenantDomain): that query can
            // transiently fail or return false during route *registration*,
            // which previously dropped the tenant route group for the request
            // and made every /api/* call 404 with "route ... could not be
            // found" until the next full page load.
            //
            // The InitializeTenancyByDomainOrSubdomain middleware performs the
            // authoritative per-request tenant lookup, and the exception
            // handler turns a genuinely unknown tenant into a clean redirect to
            // the central app (web) or a 404 JSON payload (api). So registering
            // the tenant routes here is safe for raw IPs / unknown domains too.
            $this->mapTenantRoutes();
        }
    }

    /**
     * Routes that work on any domain (no tenant/central context required).
     * Reserved for payment gateway callbacks that redirect via external URLs.
     */
    protected function mapUniversalRoutes(): void
    {
        // Flutterwave v3 uses hosted checkout with redirect_url — no universal
        // callback route needed; the redirect goes to the tenant's own success page.
    }

    /**
     * Central (SaaS) routes — setup, landing, signup. No tenant context.
     */
    protected function mapCentralRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/central.php'));
    }

    /**
     * Tenant routes — Stocky app (web, api, portal). Runs with tenant DB when host is tenant subdomain or custom domain.
     */
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
