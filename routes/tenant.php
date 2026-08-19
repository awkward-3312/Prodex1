<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\ProdexManualController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant-specific routes
|--------------------------------------------------------------------------
|
| This file is loaded directly by TenancyServiceProvider. Keep routes here
| when they must be guaranteed to exist on tenant domains independently of
| the legacy RouteServiceProvider mapping flow.
|
*/

Route::prefix('api/prodex-manual')
    ->middleware([
        'api',
        PreventAccessFromCentralDomains::class,
        InitializeTenancyByDomainOrSubdomain::class,
        'tenant.active',
        'auth:api',
        'Is_Active',
        'request.safety',
        'token.timeout',
        'tenant.subscribed',
        'tenant.activity',
    ])
    ->group(function () {
        Route::get('/categories', [ProdexManualController::class, 'categories']);
        Route::get('/articles', [ProdexManualController::class, 'articles']);
        Route::get('/articles/{id}', [ProdexManualController::class, 'show'])
            ->whereNumber('id');
    });
