<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class KitchenOrderPolicy
{
    use HandlesAuthorization;

    /**
     * View the kitchen display board.
     */
    public function view(User $user)
    {
        $permission = Permission::where('name', 'kitchen_display_view')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    /**
     * Manage kitchen orders (send, change status, assign staff).
     */
    public function manage(User $user)
    {
        $permission = Permission::where('name', 'kitchen_display_manage')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
