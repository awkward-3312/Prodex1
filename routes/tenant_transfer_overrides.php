<?php

use Illuminate\Support\Facades\Route;

$transferMiddleware = [
    'auth:api',
    'Is_Active',
    'request.safety',
    'token.timeout',
    'tenant.subscribed',
    'tenant.activity',
    'tenant.feature:transfers',
];

Route::middleware($transferMiddleware)
    ->get('transfers', 'FinalTransferController@index');

// tenant_api.php still declares the legacy PDF route in its historical public
// print block. This override file is loaded afterwards by RouteServiceProvider,
// so redefining the same method/URI here makes the effective tenant route
// authenticated and keeps EnforceWarehouseScope able to authorize the user.
Route::middleware($transferMiddleware)
    ->get('transfer_pdf/{id}', 'TransferController@transfer_pdf');
