<?php

namespace App\Providers;

use App\Models\Central\GeneralSetting;
use App\Models\Setting;
use App\Services\BatchService;
use App\Services\IdempotentTransferLogisticsService;
use App\Services\LocationAwareBatchService;
use App\Services\LocationAwareSerialNumberService;
use App\Services\SerialNumberService;
use App\Services\TenantLimitsService;
use App\Services\TransferLogisticsService;
use App\Tenant;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
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

        // Keep the public service contract stable while using the hardened
        // implementation that aligns aggregate/batch units and makes physical
        // receiving safe to retry after double-clicks or connection loss.
        $this->app->singleton(TransferLogisticsService::class, IdempotentTransferLogisticsService::class);

        // Existing controllers continue resolving the historical service names.
        // Location-aware implementations delegate to the legacy behavior unless
        // the document explicitly carries inventory_location_id.
        $this->app->singleton(BatchService::class, LocationAwareBatchService::class);
        $this->app->singleton(SerialNumberService::class, LocationAwareSerialNumberService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        Schema::defaultStringLength(191);

        // Load the complete Spanish SuperAdmin translation group before applying
        // PRODEX-specific overrides. Laravel's Translator::addLines() marks the
        // target group as loaded, so calling it first would prevent super.php
        // from being loaded and unresolved keys would be rendered literally.
        Lang::load('*', 'super', 'es');

        // Spanish is PRODEX's platform baseline. These lines intentionally
        // override residual legacy English/mixed labels that still live in the
        // large historical SuperAdmin language file.
        Lang::addLines(config('prodex_spanish_ui.super_translations', []), 'es');

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
