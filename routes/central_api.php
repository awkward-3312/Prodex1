<?php

use Illuminate\Support\Facades\Route;

Route::post('mobile/tenants/resolve', [\App\Http\Controllers\Mobile\MobileTenantResolverController::class, 'resolve'])
    ->middleware('throttle:mobile-tenant-resolve');

