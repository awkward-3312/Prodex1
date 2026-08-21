<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('pos')->group(function () {
    Route::get('operational-context', 'PosOperationalContextController@show');
});
