<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])
    ->prefix('transfer-location')
    ->group(function () {
        Route::get('/options', [\App\Http\Controllers\TransferLocationController::class, 'options']);
        Route::get('/transfers/{transferId}/context', [\App\Http\Controllers\TransferLocationController::class, 'context'])->whereNumber('transferId');
        Route::get('/{locationId}/products', [\App\Http\Controllers\TransferLocationController::class, 'products'])->whereNumber('locationId');
        Route::get('/{locationId}/products/{productId}', [\App\Http\Controllers\TransferLocationController::class, 'product'])
            ->whereNumber('locationId')->whereNumber('productId');
        Route::get('/{locationId}/batches/{productId}/{variantId?}', [\App\Http\Controllers\TransferLocationController::class, 'batches'])
            ->whereNumber('locationId')->whereNumber('productId')->whereNumber('variantId');
    });
