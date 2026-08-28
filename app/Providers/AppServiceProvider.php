<?php

namespace App\Providers;

use App\Models\Central\GeneralSetting;
use App\Models\Setting;
use App\Services\BatchService;
use App\Services\BusinessAuditService;
use App\Services\FinalTransferLogisticsService;
use App\Services\LocationAwareBatchService;
use App\Services\LocationAwareSerialNumberService;
use App\Services\PosAwareSarFiscalSaleService;
use App\Services\ProdexTenantSchemaHealthService;
use App\Services\SarFiscalSaleService;
use App\Services\SerialNumberService;
use App\Services\TenantLimitsService;
use App\Services\TenantSchemaHealthService;
use App\Services\TransferLogisticsService;
use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
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
    public function register()
    {
        $this->app->singleton(TenantLimitsService::class);
        $this->app->singleton(TenantSchemaHealthService::class, ProdexTenantSchemaHealthService::class);
        $this->app->singleton(BusinessAuditService::class);

        // One public contract for both generations of transfer. The final binding
        // includes retry safety, physical locations, batches, serials/IMEI and
        // discrepancy reconciliation while legacy warehouse transfers still fall back.
        $this->app->singleton(TransferLogisticsService::class, FinalTransferLogisticsService::class);

        $this->app->singleton(BatchService::class, LocationAwareBatchService::class);
        $this->app->singleton(SerialNumberService::class, LocationAwareSerialNumberService::class);

        // SAR remains mandatory when enabled. Modern POS sales resolve the fiscal
        // point through their physical cash drawer while legacy sales keep the
        // original warehouse-based resolver as a fallback.
        $this->app->singleton(SarFiscalSaleService::class, PosAwareSarFiscalSaleService::class);
    }

    public function boot()
    {
        Schema::defaultStringLength(191);

        // Centralized, fail-open audit trail for critical business models. The
        // service filters the model list before touching the database, so normal
        // framework/internal Eloquent events are effectively ignored.
        Event::listen('eloquent.*: *', function (string $eventName, array $data) {
            if (! preg_match('/^eloquent\.(created|updated|deleted): /', $eventName, $matches)) {
                return;
            }

            $model = $data[0] ?? null;
            if ($model instanceof Model) {
                app(BusinessAuditService::class)->record($matches[1], $model);
            }
        });

        Lang::load('*', 'super', 'es');
        Lang::addLines(config('prodex_spanish_ui.super_translations', []), 'es');

        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        Blade::if('tenantFeature', function (string $feature) {
            return app(TenantLimitsService::class)->hasFeature($feature);
        });

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
                'api', 'setup', 'update', 'password', 'online_store', 'super',
                'register', 'checkout', 'workspace', 'errors',
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
