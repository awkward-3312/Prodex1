<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])->group(function () {
    Route::get('/transfer-logistics/incoming', 'TransferLogisticsController@incoming');
    Route::get('/transfer-logistics/notifications', 'TransferLogisticsController@notifications');
    Route::post('/transfer-logistics/notifications/{notificationId}/read', 'TransferLogisticsController@markNotificationRead');
    Route::get('/transfer-logistics/scan/{token}', 'TransferLogisticsController@showByToken');
    Route::get('/transfer-logistics/{id}/qr', 'TransferLogisticsController@qrPayload');
    Route::get('/transfer-logistics/{id}', 'TransferLogisticsController@show');
    Route::post('/transfer-logistics/{id}/receive', 'TransferLogisticsController@receive');
});
