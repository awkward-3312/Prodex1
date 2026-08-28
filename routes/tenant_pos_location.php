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

        // Registered after tenant_api.php so modern location-native POS requests
        // pass through server-side price/total normalization before the existing
        // CreatePOS controller. Legacy non-location sales remain unchanged.
        Route::post('/pos/create_pos', 'PosController@CreatePOS')
            ->middleware(\App\Http\Middleware\NormalizeModernPosSaleRequest::class);

        // Registered after tenant_api.php so this replaces the legacy recent-draft
        // listing for the same URI. Location-only cashiers can therefore see their
        // own held sales even when they have no UserWarehouse compatibility rows.
        Route::get('/get_draft_sales', [
            \App\Http\Controllers\PosDraftRecentController::class,
            'index',
        ]);
    });
