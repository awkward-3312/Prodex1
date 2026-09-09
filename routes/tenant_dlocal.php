<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')
    ->post('/billing/dlocal/checkout/{plan}', [\App\Http\Controllers\Tenant\DLocalBillingController::class, 'process'])
    ->name('billing.dlocal.process');
