<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductSerialPolicy
{
    use HandlesAuthorization;

    protected function checkPermission(User $user): bool
    {
        return $this->hasPermission($user, 'serial_numbers');
    }

    protected function hasPermission(User $user, string $permissionName): bool
    {
        $permission = Permission::where('name', $permissionName)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function update(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function delete(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function serial_numbers_report(User $user): bool
    {
        return $this->hasPermission($user, 'serial_numbers_report');
    }
}
