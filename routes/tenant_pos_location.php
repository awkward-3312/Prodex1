<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])
    ->group(function () {
        Route::get('/pos/location-inventory/{locationId}/catalog', [
            \App\Http\Controllers\PosLocationCatalogController::class,
            'index',
        ])->whereNumber('locationId');

        Route::get('/pos/location-inventory/{locationId}/stock-map', [
            \App\Http\Controllers\PosLocationInventoryController::class,
            'stockMap',
        ])->whereNumber('locationId');

        Route::get('/pos/location-inventory/{locationId}/changes', [
            \App\Http\Controllers\PosLocationInventoryController::class,
            'changes',
        ])->whereNumber('locationId');

        Route::get('/pos/location-inventory/{locationId}/products/{productId}', [
            \App\Http\Controllers\PosLocationInventoryController::class,
            'show',
        ])->whereNumber('locationId')->whereNumber('productId');
    });
