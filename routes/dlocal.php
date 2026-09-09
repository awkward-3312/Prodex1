<?php

use Illuminate\Support\Facades\Route;

// dLocal server-to-server notification. Signature is validated by DLocalGateway.
Route::post('/webhook/dlocal', [\App\Http\Controllers\Central\WebhookController::class, 'handle'])
    ->defaults('gateway', 'dlocal')
    ->middleware('throttle:120,1')
    ->name('webhook.dlocal');

// Browser return for REDIRECT payments. dLocal POSTs here; this endpoint only
// validates the callback and redirects. It never marks a payment as paid.
Route::post('/payments/dlocal/return', \App\Http\Controllers\Central\DLocalReturnController::class)
    ->middleware('throttle:120,1')
    ->name('dlocal.return');
