<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CashDrawerPolicy
{
    use HandlesAuthorization;

    public function view(User $user): bool
    {
        return $user->hasPermissionName('cash_drawers_view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionName('cash_drawers_add');
    }

    public function update(User $user): bool
    {
        return $user->hasPermissionName('cash_drawers_edit');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermissionName('cash_drawers_delete');
    }
}
