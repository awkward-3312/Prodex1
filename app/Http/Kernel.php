<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewarePriority = [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\Authenticate::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

    protected $middleware = [
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \App\Http\Middleware\ServeSetupWhenNotInstalled::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\SetSessionConfig::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EnsureNotUpdating::class,
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SafeCreateFreshApiToken::class,
        ],

        'store' => [
            'store.auth',
        ],

        'api' => [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SetPdfLocale::class,
            // Both are constant-time no-ops outside TransferController actions.
            \App\Http\Middleware\LockTransferDispatchStock::class,
            \App\Http\Middleware\ProtectDispatchedTransferMutation::class,
        ],
    ];

    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'store.auth' => \App\Http\Middleware\StoreAuthenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'Is_Active' => \App\Http\Middleware\Is_Active::class,
        'store.data' => \App\Http\Middleware\StoreDataMiddleware::class,
        'setlocale' => \App\Http\Middleware\SetLocale::class,
        'XSS' => \App\Http\Middleware\XSS::class,
        'request.safety' => \App\Http\Middleware\RequestSafety::class,
        'store.enabled' => \App\Http\Middleware\EnsureStoreEnabled::class,
        'token.timeout' => \App\Http\Middleware\EnforceApiTokenTimeout::class,
        'pdf.locale' => \App\Http\Middleware\SetPdfLocale::class,
        'portal.auth' => \App\Http\Middleware\EnsurePortalAuth::class,
        'auth.central' => \App\Http\Middleware\EnsureCentralAuth::class,
        'central.permission' => \App\Http\Middleware\CheckCentralPermission::class,
        'tenant.active' => \App\Http\Middleware\EnsureTenantNotSuspended::class,
        'tenant.limit' => \App\Http\Middleware\CheckTenantLimits::class,
        'tenant.feature' => \App\Http\Middleware\CheckTenantFeature::class,
        'tenant.subscribed' => \App\Http\Middleware\EnsureActiveSubscription::class,
        'tenant.activity' => \App\Http\Middleware\TrackTenantActivity::class,
        'transfer.dispatch.lock' => \App\Http\Middleware\LockTransferDispatchStock::class,
    ];
}
