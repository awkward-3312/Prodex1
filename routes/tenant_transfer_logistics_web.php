<?php

use Illuminate\Support\Facades\Route;

// The QR intentionally opens a web URL so a phone's native camera can scan it.
// No transfer data is exposed here: the SPA receiving API remains authenticated
// and validates both the transfer_receive permission and destination warehouse.
Route::get('/transfer-receive/{token}', function (string $token) {
    return redirect('/app/transfers/list?receive_token='.rawurlencode($token));
})->where('token', '[A-Za-z0-9\-]+');
