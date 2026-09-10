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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
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

        // Paddle was originally configured only through .env. Once the Super Admin
        // has a Paddle row, that row becomes authoritative for activation,
        // environment and credentials while .env remains a safe migration fallback.
        $this->configurePaddleGatewaySettings();

        // Behind the production Nginx proxy TLS terminates upstream. TrustProxies
        // already restores the real scheme/host from the forwarded headers; this
        // is the belt-and-suspenders so every generated URL (emails, redirects,
        // canonical tags) is https in production. Never forced locally.
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        $this->configurePublicRateLimiters();

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

    /**
     * Load Paddle platform-billing settings from the central Super Admin table.
     *
     * The existing Paddle checkout reads config('services.paddle.*'), so hydrating
     * that config here lets the Super Admin control Paddle without duplicating the
     * checkout implementation or exposing the private API key to the browser.
     *
     * If no Paddle row exists yet, the previous .env configuration keeps working.
     * Once a row exists and is disabled, Paddle checkout credentials are nulled so
     * the gateway disappears from tenant checkout immediately on the next request.
     */
    protected function configurePaddleGatewaySettings(): void
    {
        if (! file_exists(base_path('storage/app/public/installed'))) {
            return;
        }

        try {
            $row = DB::connection('central')
                ->table('payment_gateway_settings')
                ->where('gateway', 'paddle')
                ->first();

            if (! $row) {
                return;
            }

            $rawCredentials = json_decode($row->credentials ?? '{}', true);
            $rawCredentials = is_array($rawCredentials) ? $rawCredentials : [];

            $credential = function (string $key, $fallback = null) use ($rawCredentials) {
                $value = trim((string) ($rawCredentials[$key] ?? ''));

                if ($value === '') {
                    return $fallback;
                }

                if (str_starts_with($value, 'eyJ')) {
                    try {
                        return trim(Crypt::decryptString($value));
                    } catch (\Throwable $e) {
                        Log::warning("Failed to decrypt Paddle credential [{$key}]: {$e->getMessage()}");
                        return $fallback;
                    }
                }

                return $value;
            };

            $isActive = (bool) $row->is_active;

            config([
                'services.paddle.environment' => (bool) $row->test_mode ? 'sandbox' : 'live',
                'services.paddle.client_side_token' => $isActive
                    ? $credential('client_side_token', config('services.paddle.client_side_token'))
                    : null,
                'services.paddle.api_key' => $credential('api_key', config('services.paddle.api_key')),
                'services.paddle.webhook_secret' => $credential('webhook_secret', config('services.paddle.webhook_secret')),
                'services.paddle.sandbox_tenant' => $credential('sandbox_tenant', config('services.paddle.sandbox_tenant')),
                'services.paddle.starter_monthly_price_id' => $isActive
                    ? $credential('starter_monthly_price_id', config('services.paddle.starter_monthly_price_id'))
                    : null,
                'services.paddle.starter_yearly_price_id' => $isActive
                    ? $credential('starter_yearly_price_id', config('services.paddle.starter_yearly_price_id'))
                    : null,
            ]);
        } catch (\Throwable $e) {
            // Fail open during install/maintenance or a temporary central DB outage.
            // Existing .env values remain available and the rest of PRODEX boots.
            Log::warning('Could not load Paddle Super Admin settings: '.$e->getMessage());
        }
    }

    /**
     * Abuse protection for unauthenticated public endpoints that send mail or
     * create resources. Keyed on IP + a normalized identifier so shared NAT /
     * corporate egress is not blanket-banned, with a wider per-IP ceiling.
     */
    protected function configurePublicRateLimiters(): void
    {
        $ident = function ($request): string {
            $id = strtolower(trim((string) ($request->input('admin_email')
                ?: $request->input('email')
                ?: $request->input('subdomain'))));

            return sha1($request->ip().'|'.$id);
        };

        \Illuminate\Support\Facades\RateLimiter::for('central-auth', function ($request) use ($ident) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($ident($request)),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('central-register', function ($request) use ($ident) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(4)->by($ident($request)),
                \Illuminate\Cache\RateLimiting\Limit::perDay(40)->by('ip:'.$request->ip()),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('central-checkout', function ($request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(12)->by('ip:'.$request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('mobile-tenant-resolve', function ($request) {
            $workspace = strtolower(trim((string) $request->input('workspace')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('workspace:'.sha1($request->ip().'|'.$workspace)),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(80)->by('ip:'.$request->ip()),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('mobile-login', function ($request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('login:'.sha1($request->ip().'|'.$email)),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(25)->by('ip:'.$request->ip()),
            ];
        });
    }
}
