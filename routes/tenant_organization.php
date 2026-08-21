<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('organization')->group(function () {
    Route::get('branches', 'Organization\\BranchController@index');
    Route::get('branches/options', 'Organization\\BranchController@options');
    Route::post('branches', 'Organization\\BranchController@store');
    Route::put('branches/{id}', 'Organization\\BranchController@update');
    Route::delete('branches/{id}', 'Organization\\BranchController@destroy');
    Route::put('branches/{branchId}/employees/{employeeId}', 'Organization\\BranchController@assignEmployee');
    Route::post('branches/{branchId}/inventory-locations', 'Organization\\BranchController@storeInventoryLocation');

    // User management exists independently from HRM. Creating a login consumes
    // the same max_users plan limit regardless of whether it starts from an employee.
    Route::get('user-access/options', 'Organization\\UserAccessController@options');
    Route::post('user-access', 'Organization\\UserAccessController@store')->middleware('tenant.limit:max_users');

    Route::get('employee-access', 'Organization\\EmployeeAccessController@index');
    Route::post('employee-access/{employeeId}/create', 'Organization\\EmployeeAccessController@create')->middleware('tenant.limit:max_users');
    Route::put('employee-access/{employeeId}/link', 'Organization\\EmployeeAccessController@link');
    Route::delete('employee-access/{employeeId}/link', 'Organization\\EmployeeAccessController@unlink');

    Route::get('role-permission-templates', 'Organization\\RoleTemplateController@index');
});
