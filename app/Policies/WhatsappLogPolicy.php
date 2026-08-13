<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class WhatsappLogPolicy
{
    use HandlesAuthorization;

    protected function allowed(User $user): bool
    {
        $permission = Permission::where('name', 'whatsapp_logs')->first();

        return $permission ? $user->hasRole($permission->roles) : $user->roles->contains('id', 1);
    }

    public function view(User $user)
    {
        return $this->allowed($user);
    }

    public function delete(User $user)
    {
        return $this->allowed($user);
    }
}
