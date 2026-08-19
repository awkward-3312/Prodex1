<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Central\Super\BankAccountSettingsController;

Route::middleware(['web', 'auth.central', 'central.permission:settings'])
    ->prefix('super/settings')
    ->name('super.settings.')
    ->group(function () {
        Route::get('/bank-accounts', [BankAccountSettingsController::class, 'index'])
            ->name('bank-accounts');
        Route::put('/bank-accounts', [BankAccountSettingsController::class, 'update'])
            ->name('bank-accounts.update');
    });
