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
    // These overrides are loaded after tenant_api.php and after the workflow
    // routes. Keeping the same method/URI makes every existing Vue caller use
    // the modern business rules without maintaining two lifecycle semantics.
    Route::get('transfers', 'FinalTransferController@index');
    Route::post('transfers', 'FinalTransferController@store');
    // The {transfer} parameter name (not {id}) is deliberate: it collides with the
    // legacy Route::resource('transfers', 'TransferController') PUT/PATCH URI in
    // tenant_api.php so this later registration actually overrides it. With {id}
    // the URIs differ and the resource route wins, sending edits back to the
    // legacy warehouse-based update. FinalTransferController@update takes the value
    // positionally into its $id argument, exactly like the legacy controller.
    Route::put('transfers/{transfer}', 'FinalTransferController@update');
    Route::patch('transfers/{transfer}', 'FinalTransferController@update');

    // Historical transfer list actions still POST to these URIs. Route them to
    // the explicit workflow so approval is authorization only; physical stock
    // leaves the source exclusively through the separate dispatch action.
    Route::post('transfers/{id}/approve', 'TransferWorkflowController@approve');
    Route::post('transfers/{id}/reject', 'TransferWorkflowController@reject');

    // tenant_api.php still declares the legacy PDF route in its historical
    // public print block. Redefine it here so the effective route is protected.
    Route::get('transfer_pdf/{id}', 'TransferController@transfer_pdf');
});
