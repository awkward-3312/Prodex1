<?php

use App\Http\Controllers\hrm\AttendanceIntegrationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])->group(function () {
    Route::get('/attendance-integrations/devices', [AttendanceIntegrationsController::class, 'devices']);
    Route::post('/attendance-integrations/devices', [AttendanceIntegrationsController::class, 'storeDevice']);
    Route::put('/attendance-integrations/devices/{id}', [AttendanceIntegrationsController::class, 'updateDevice']);
    Route::delete('/attendance-integrations/devices/{id}', [AttendanceIntegrationsController::class, 'destroyDevice']);

    Route::get('/employees/{employeeId}/attendance-identifiers', [AttendanceIntegrationsController::class, 'employeeIdentifiers']);
    Route::post('/employees/{employeeId}/attendance-identifiers', [AttendanceIntegrationsController::class, 'storeEmployeeIdentifier']);
    Route::delete('/employees/{employeeId}/attendance-identifiers/{identifierId}', [AttendanceIntegrationsController::class, 'destroyEmployeeIdentifier']);

    Route::get('/attendance-integrations/punches', [AttendanceIntegrationsController::class, 'punches']);
    Route::post('/attendance-integrations/import', [AttendanceIntegrationsController::class, 'importPunches']);
});
