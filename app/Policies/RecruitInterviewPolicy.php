<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RecruitInterviewPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        $permission = Permission::where('name', 'recruit_interview')->first();

        return $user->hasRole($permission->roles);
    }

    public function create(User $user)
    {
        $permission = Permission::where('name', 'recruit_interview')->first();

        return $user->hasRole($permission->roles);
    }

    public function update(User $user)
    {
        $permission = Permission::where('name', 'recruit_interview')->first();

        return $user->hasRole($permission->roles);
    }

    public function delete(User $user)
    {
        $permission = Permission::where('name', 'recruit_interview')->first();

        return $user->hasRole($permission->roles);
    }
}
