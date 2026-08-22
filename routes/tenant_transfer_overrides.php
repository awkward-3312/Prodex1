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

Route::middleware($transferMiddleware)->group(function () {
    // These overrides are loaded after tenant_api.php. Keeping the same
    // method/URI makes the effective modern transfer endpoints use the final
    // controller, which enforces the business routing rules before delegating
    // to the legacy persistence flow.
    Route::get('transfers', 'FinalTransferController@index');
    Route::post('transfers', 'FinalTransferController@store');
    Route::put('transfers/{id}', 'FinalTransferController@update');
    Route::patch('transfers/{id}', 'FinalTransferController@update');

    // tenant_api.php still declares the legacy PDF route in its historical
    // public print block. Redefine it here so the effective route is protected.
    Route::get('transfer_pdf/{id}', 'TransferController@transfer_pdf');
});
