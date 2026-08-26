<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])
    ->prefix('pos/registers')
    ->group(function () {
        Route::get('current/{userId}', [\App\Http\Controllers\PosCashRegisterController::class, 'getCurrentRegister'])
            ->whereNumber('userId');
        Route::post('open', [\App\Http\Controllers\PosCashRegisterController::class, 'openRegister']);
        Route::post('cash-in-out', [\App\Http\Controllers\PosCashRegisterController::class, 'cashInOut']);
        Route::post('close', [\App\Http\Controllers\PosCashRegisterController::class, 'closeRegister']);
    });
