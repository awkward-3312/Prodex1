<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])->group(function () {
    Route::get('/transfer-logistics/incoming', 'FinalTransferLogisticsController@incoming');
    Route::get('/transfer-logistics/notifications', 'FinalTransferLogisticsController@notifications');
    Route::post('/transfer-logistics/notifications/{notificationId}/read', 'FinalTransferLogisticsController@markNotificationRead');

    Route::get('/transfer-logistics/issues', 'TransferDiscrepancyController@index');
    Route::post('/transfer-logistics/issues/{id}/resolve', 'LocationAwareTransferDiscrepancyController@resolve');

    Route::get('/transfer-logistics/scan/{token}', 'FinalTransferLogisticsController@showByToken');
    Route::get('/transfer-logistics/{id}/qr', 'FinalTransferLogisticsController@qrPayload');
    Route::get('/transfer-logistics/{id}', 'FinalTransferLogisticsController@show');
    Route::post('/transfer-logistics/{id}/receive', 'FinalTransferLogisticsController@receive');
});
