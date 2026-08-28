<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])
    ->group(function () {
        Route::get('report/cash_registers_native', 'SafePosCashRegisterReportController@report');

        // Loaded after tenant_api.php on purpose. These read-only overrides migrate
        // sale visibility to Branch/InventoryLocation while keeping legacy warehouse
        // sales readable during the cutover.
        Route::get('dashboard_data', 'OperationalDashboardController@dashboard_data');
        Route::get('sales', 'OperationalSalesController@index');

        Route::get('report/sales', 'OperationalReportController@Report_Sales');
        Route::get('report/users', 'OperationalReportController@users_Report');
        Route::get('report/get_sales_by_user', 'OperationalReportController@get_sales_by_user');
        Route::get('report/seller_report', 'OperationalReportController@seller_report');
        Route::get('report/top_products', 'OperationalReportController@report_top_products');
        Route::get('report/top_customers', 'OperationalReportController@report_top_customers');
        Route::get('report/sales_by_category_report', 'OperationalReportController@sales_by_category_report');
        Route::get('report/sales_by_brand_report', 'OperationalReportController@sales_by_brand_report');
    });
