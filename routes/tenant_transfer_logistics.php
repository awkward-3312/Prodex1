<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])->group(function () {
    Route::get('/transfer-logistics/incoming', 'FinalTransferLogisticsController@incoming');
    Route::get('/transfer-logistics/notifications', 'FinalTransferLogisticsController@notifications');
    Route::post('/transfer-logistics/notifications/{notificationId}/read', 'FinalTransferLogisticsController@markNotificationRead');

    Route::get('/transfer-logistics/issues', 'FinalTransferDiscrepancyController@index');
    Route::post('/transfer-logistics/issues/{id}/resolve', 'FinalTransferDiscrepancyController@resolve');

    // Resolve the physical inventory destination used by transfer-generated damage records.
    Route::get('/transfer-logistics/damage-location/{id}', 'TransferDamageLocationController@show');

    Route::get('/transfer-logistics/scan/{token}', 'FinalTransferLogisticsController@showByToken');
    Route::get('/transfer-logistics/{id}/qr', 'FinalTransferLogisticsController@qrPayload');
    Route::get('/transfer-logistics/{id}', 'FinalTransferLogisticsController@show');
    Route::post('/transfer-logistics/{id}/receive', 'FinalTransferLogisticsController@receive');

    // Explicit operational workflow. Approval authorizes the transfer; dispatch is
    // the physical stock movement and the point at which the destination is notified.
    Route::get('/transfer-workflow/reference/{reference}', 'TransferWorkflowController@showByReference');
    Route::get('/transfer-workflow/{id}', 'TransferWorkflowController@show');
    Route::post('/transfer-workflow/{id}/approve', 'TransferWorkflowController@approve');
    Route::post('/transfer-workflow/{id}/reject', 'TransferWorkflowController@reject');
    Route::post('/transfer-workflow/{id}/dispatch', 'TransferWorkflowController@dispatchTransfer');
});
