<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return mixed
     */
    public function viewAny(User $user)
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function view(User $user)
    {
        return $user->hasPermissionName('Sales_view');
    }

    /**
     * Determine whether the user can create models.
     *
     * @return mixed
     */
    public function create(User $user)
    {
        return $user->hasPermissionName('Sales_add');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function update(User $user)
    {
        return $user->hasPermissionName('Sales_edit');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function delete(User $user)
    {
        return $user->hasPermissionName('Sales_delete');
    }

    public function Reports_sales(User $user)
    {
        return $user->hasPermissionName('Reports_sales');
    }

    public function Sales_pos(User $user)
    {
        return $user->hasPermissionName('Pos_view');
    }

    public function product_sales_report(User $user)
    {
        return $user->hasPermissionName('product_sales_report');
    }

    public function report_sales_by_category(User $user)
    {
        return $user->hasPermissionName('report_sales_by_category');
    }

    public function report_sales_by_brand(User $user)
    {
        return $user->hasPermissionName('report_sales_by_brand');
    }

    public function draft_invoices_report(User $user)
    {
        return $user->hasPermissionName('draft_invoices_report');
    }

    public function discount_summary_report(User $user)
    {
        return $user->hasPermissionName('discount_summary_report');
    }

    public function tax_summary_report(User $user)
    {
        return $user->hasPermissionName('tax_summary_report');
    }

    public function cash_register_report(User $user)
    {
        return $user->hasPermissionName('cash_register_report');
    }

    public function customer_display_screen_setup(User $user)
    {
        return $user->hasPermissionName('customer_display_screen_setup');
    }

    public function quickbooks_settings(User $user)
    {
        return $user->hasPermissionName('quickbooks_settings');
    }

    public function zatca_settings(User $user)
    {
        return $user->hasPermissionName('zatca_settings');
    }

    public function customer_loyalty_points_report(User $user)
    {
        return $user->hasPermissionName('customer_loyalty_points_report');
    }

    public function report_warranty(User $user)
    {
        return $user->hasPermissionName('report_warranty');
    }

    public function check_record(User $user, $sale)
    {
        return $user->id === $sale->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function restore(User $user)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function forceDelete(User $user)
    {
        //
    }
}
