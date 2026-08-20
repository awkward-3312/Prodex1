<?php

use Illuminate\Support\Facades\Route;

// Registered after tenant_api.php so the fiscal renderer becomes the effective
// direct-network-print route without disturbing the legacy non-SAR fallback.
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])
    ->post('direct_network_print/{id}', [\App\Http\Controllers\SarDirectNetworkPrintController::class, 'print']);
