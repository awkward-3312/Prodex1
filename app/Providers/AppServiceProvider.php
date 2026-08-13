<?php

namespace App\Providers;

use App\Models\Central\GeneralSetting;
use App\Models\Setting;
use App\Services\TenantLimitsService;
use App\Tenant;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TenantLimitsService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        Schema::defaultStringLength(191);

        /* ADD THIS LINES */
        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        // Blade directives: @tenantFeature('pos') ... @endTenantFeature
        Blade::if('tenantFeature', function (string $feature) {
            return app(TenantLimitsService::class)->hasFeature($feature);
        });

        // Blade directive: @tenantCanCreate('max_products') ... @endTenantCanCreate
        Blade::if('tenantCanCreate', function (string $limitKey) {
            return ! app(TenantLimitsService::class)->hasReachedLimit($limitKey);
        });

        View::composer('*', function ($view) {
            if (! file_exists(base_path('storage/app/public/installed'))) {
                $view->with('currencySymbol', '$');
                $view->with('currencyCode', 'USD');
                return;
            }

            try {
                $view->with('currencySymbol', GeneralSetting::currencySymbol());
                $view->with('currencyCode', GeneralSetting::currencyCode());
            } catch (\Throwable) {
                $view->with('currencySymbol', '$');
                $view->with('currencyCode', 'USD');
            }
        });

        View::composer('central.super.layout', function ($view) {
            try {
                $pendingTenants = Tenant::where('status', Tenant::STATUS_PENDING)->latest()->get();
                $view->with('pendingTenants', $pendingTenants);
            } catch (\Throwable) {
                $view->with('pendingTenants', collect());
            }
        });

        View::composer('*', function ($view) {
            if (! file_exists(base_path('storage/app/public/installed'))) {
                $view->with('app_settings', null);
                return;
            }

            $excluded = [
                'api',
                'setup',
                'update',
                'password',
                'online_store',
                'super',
                'register',
                'checkout',
                'workspace',
                'errors',
            ];

            $firstSegment = Request::segment(1);

            if (! $firstSegment || in_array($firstSegment, $excluded)) {
                $view->with('app_settings', null);
                return;
            }

            try {
                $view->with('app_settings', Setting::first());
            } catch (\Throwable) {
                $view->with('app_settings', null);
            }
        });
    }
}
