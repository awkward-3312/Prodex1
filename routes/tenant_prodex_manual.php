<?php

use App\Http\Controllers\Tenant\ProdexManualController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])
    ->prefix('prodex-manual')
    ->group(function () {
        Route::get('/categories', [ProdexManualController::class, 'categories']);
        Route::get('/articles', [ProdexManualController::class, 'articles']);
        Route::get('/articles/{id}', [ProdexManualController::class, 'show'])->whereNumber('id');
    });
