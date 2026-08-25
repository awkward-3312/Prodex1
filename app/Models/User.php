<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'employee_id', 'firstname', 'lastname', 'username', 'email', 'password', 'phone', 'statut', 'avatar', 'role_id',
        'is_all_warehouses', 'default_warehouse_id', 'default_branch_id', 'default_inventory_location_id',
        'default_cash_drawer_id', 'record_view',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'email_verified_at' => 'datetime',
        'role_id' => 'integer',
        'statut' => 'integer',
        'is_all_warehouses' => 'integer',
        'default_warehouse_id' => 'integer',
        'default_branch_id' => 'integer',
        'default_inventory_location_id' => 'integer',
        'default_cash_drawer_id' => 'integer',
        'record_view' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function oauthAccessToken()
    {
        return $this->hasMany('\\App\\Models\\OauthAccessToken');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function assignRole(Role $role)
    {
        return $this->roles()->save($role);
    }

    public function hasRole($role)
    {
        if (is_string($role)) {
            return $this->roles->contains('name', $role);
        }

        return (bool) $role->intersect($this->roles)->count();
    }

    public function assignedWarehouses()
    {
        return $this->belongsToMany('App\\Models\\Warehouse');
    }

    public function assignedBranches()
    {
        return $this->belongsToMany(Branch::class, 'user_branches', 'user_id', 'branch_id')->withTimestamps();
    }

    public function assignedInventoryLocations()
    {
        return $this->belongsToMany(InventoryLocation::class, 'user_inventory_locations', 'user_id', 'inventory_location_id')->withTimestamps();
    }

    public function defaultWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function defaultBranch()
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function defaultInventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'default_inventory_location_id');
    }

    public function defaultCashDrawer()
    {
        return $this->belongsTo(CashDrawer::class, 'default_cash_drawer_id');
    }

    public function operationalAssignments()
    {
        return $this->hasMany(UserOperationalAssignment::class);
    }

    public function hasPermissionName(string $permissionName): bool
    {
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permissionName) {
                $query->where('name', $permissionName);
            })
            ->exists();
    }

    public function hasRecordView()
    {
        if (isset($this->record_view)) {
            return (bool) $this->record_view;
        }

        $role = $this->roles()->first();
        if ($role) {
            return $role->inRole('record_view');
        }

        return false;
    }
}
