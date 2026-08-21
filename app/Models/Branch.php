<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'name', 'type', 'phone', 'email', 'country', 'city', 'address',
        'manager_employee_id', 'default_warehouse_id', 'default_inventory_location_id', 'is_active',
    ];

    protected $casts = [
        'manager_employee_id' => 'integer',
        'default_warehouse_id' => 'integer',
        'default_inventory_location_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class, 'branch_id');
    }

    public function inventoryLocations()
    {
        return $this->hasMany(InventoryLocation::class, 'branch_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'branch_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    /**
     * Legacy compatibility only. A branch is not a warehouse; this relation is
     * retained while historical flows are migrated to InventoryLocation.
     */
    public function defaultWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function defaultInventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'default_inventory_location_id');
    }
}
