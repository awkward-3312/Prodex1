<?php

namespace App\Providers;

use App\Models\Central\CentralUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;

class RouteServiceProvider extends ServiceProvider
{
    protected $namespace = 'App\Http\Controllers';
    public const HOME = '/';

    public function boot()
    {
        $this->configureRateLimiters();

        Route::model('admin', CentralUser::class);
        Route::model('category', \App\Models\Central\KbCategory::class);
        Route::model('article', \App\Models\Central\KbArticle::class);
        parent::boot();
    }

    /**
     * Named rate limiters for authentication endpoints. Keyed by email+IP so a
     * single shared IP (e.g. several POS terminals in one store) is not locked
     * out by one attacker, while credential stuffing against many accounts from
     * one IP is still capped.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $perIdentity = $email !== '' ? $email.'|'.$request->ip() : (string) $request->ip();

            return [
                Limit::perMinute(6)->by($perIdentity),
                Limit::perMinute(40)->by((string) $request->ip()),
            ];
        });

        RateLimiter::for('password-email', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(4)->by(($email !== '' ? $email.'|' : '').$request->ip()),
                Limit::perHour(20)->by((string) $request->ip()),
            ];
        });
    }

    public function map()
    {
        if (app()->runningInConsole()) { $this->mapCentralRoutes(); return; }
        if (! file_exists(base_path('storage/app/public/installed'))) { $this->mapCentralRoutes(); return; }

        $this->mapUniversalRoutes();
        $host = request()->getHost();
        $centralDomains = config('tenancy.central_domains', []);
        in_array($host, $centralDomains, true) ? $this->mapCentralRoutes() : $this->mapTenantRoutes();
    }

    protected function mapUniversalRoutes(): void {}

    protected function mapCentralRoutes(): void
    {
        Route::middleware('web')->namespace($this->namespace)->group(base_path('routes/central.php'));
        Route::namespace($this->namespace)->group(base_path('routes/central_bank_accounts.php'));
    }

    protected function mapTenantRoutes(): void
    {
        $tenancy = [
            \App\Http\Middleware\RedirectSetupToCentral::class,
            PreventAccessFromCentralDomains::class,
            InitializeTenancyByDomainOrSubdomain::class,
            'tenant.active',
            // Normalize the modern POS payload only after tenancy is initialized.
            // The middleware is a no-op for every other tenant request, but placing
            // it here guarantees CreatePOS sees the compatibility warehouse value
            // before legacy validation regardless of duplicate route resolution.
            \App\Http\Middleware\NormalizeModernPosSaleRequest::class,
            \App\Http\Middleware\EnforceWarehouseScope::class,
        ];

        Route::middleware(array_merge(['web'], $tenancy))->namespace($this->namespace)
            ->group(base_path('routes/tenant_transfer_logistics_web.php'));
        Route::middleware(array_merge(['web'], $tenancy))->namespace($this->namespace)
            ->group(base_path('routes/tenant_web.php'));

        foreach ([
            'tenant_api.php',
            'tenant_organization.php',
            'tenant_pos_context.php',
            'tenant_pos_location.php',
            'tenant_pos_register.php',
            'tenant_pos_reports.php',
            'tenant_transfer_locations.php',
            'tenant_transfer_logistics.php',
            'tenant_transfer_overrides.php',
            'tenant_attendance_integrations.php',
            'tenant_sar_overrides.php',
        ] as $routeFile) {
            Route::prefix('api')
                ->middleware(array_merge(['api'], $tenancy))
                ->namespace($this->namespace)
                ->group(base_path('routes/'.$routeFile));
        }

        Route::middleware(array_merge(['web'], $tenancy))->namespace($this->namespace)
            ->group(base_path('routes/portal.php'));
    }
}
