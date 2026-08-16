<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountPolicy
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
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return mixed
     */
    public function view(User $user)
    {
        return $user->hasPermissionName('account');
    }

    /**
     * Determine whether the user can create models.
     *
     * @return mixed
     */
    public function create(User $user)
    {
        return $user->hasPermissionName('account');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return mixed
     */
    public function update(User $user)
    {
        return $user->hasPermissionName('account');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return mixed
     */
    public function delete(User $user)
    {
        return $user->hasPermissionName('account');
    }

    public function accounting_dashboard(User $user)
    {
        return $user->hasPermissionName('accounting_dashboard');
    }

    public function chart_of_accounts(User $user)
    {
        return $user->hasPermissionName('chart_of_accounts');
    }

    public function journal_entries(User $user)
    {
        return $user->hasPermissionName('journal_entries');
    }

    public function trial_balance(User $user)
    {
        return $user->hasPermissionName('trial_balance');
    }

    public function accounting_profit_loss(User $user)
    {
        return $user->hasPermissionName('accounting_profit_loss');
    }

    public function balance_sheet(User $user)
    {
        return $user->hasPermissionName('balance_sheet');
    }

    public function accounting_tax_report(User $user)
    {
        return $user->hasPermissionName('accounting_tax_report');
    }

    public function check_record(User $user, $expenseCategory)
    {
        return $user->id === $expenseCategory->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return mixed
     */
    public function restore(User $user)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return mixed
     */
    public function forceDelete(User $user)
    {
        //
    }
}
