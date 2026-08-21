<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDrawer extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'warehouse_id',
        'branch_id',
        'inventory_location_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'branch_id' => 'integer',
        'inventory_location_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Legacy CD/warehouse relation retained during inventory transition. */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
