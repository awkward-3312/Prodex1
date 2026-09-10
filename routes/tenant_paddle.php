<?php

use App\Http\Controllers\Tenant\PaddleBillingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api'])->prefix('billing/paddle')->group(function () {
    Route::post('/prepare', [PaddleBillingController::class, 'prepare'])
        ->middleware('throttle:30,1')
        ->name('tenant.billing.paddle.prepare');
});
