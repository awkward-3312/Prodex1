<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity', 'tenant.feature:transfers'])
    ->get('transfers', 'FinalTransferController@index');
