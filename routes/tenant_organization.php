<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('organization')->group(function () {
    Route::get('branches', 'Organization\\BranchController@index');
    Route::get('branches/options', 'Organization\\BranchController@options');
    Route::post('branches', 'Organization\\BranchController@store');
    Route::put('branches/{id}', 'Organization\\BranchController@update');
    Route::delete('branches/{id}', 'Organization\\BranchController@destroy');
    Route::put('branches/{branchId}/employees/{employeeId}', 'Organization\\BranchController@assignEmployee');
});
