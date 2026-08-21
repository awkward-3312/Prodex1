<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'branch_id', 'default_inventory_location_id', 'name', 'mobile', 'country', 'city', 'email', 'zip',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'default_inventory_location_id' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany('App\Models\User');
    }

    /**
     * Historical bin/location model inside legacy warehouses. It stays intact
     * until stock is migrated away from product_warehouse.
     */
    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function inventoryLocations()
    {
        return $this->hasMany(InventoryLocation::class, 'warehouse_id');
    }

    public function defaultInventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'default_inventory_location_id');
    }
}
