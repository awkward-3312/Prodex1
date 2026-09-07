<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SarPointOfIssue extends Model
{
    protected $table = 'sar_points_of_issue';

    protected $fillable = [
        'establishment_code', 'point_code', 'name', 'address',
        'branch_id', 'inventory_location_id', 'cash_drawer_id',
        'warehouse_id', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'branch_id' => 'integer',
        'inventory_location_id' => 'integer',
        'cash_drawer_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function authorizations()
    {
        return $this->hasMany(SarAuthorization::class, 'point_of_issue_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function cashDrawer()
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    /**
     * Modern POS resolution: a point is the fiscal identity of exactly one
     * Branch -> InventoryLocation -> CashDrawer triple.
     */
    public function scopeForOperationalContext($query, int $branchId, int $inventoryLocationId, int $cashDrawerId)
    {
        return $query->where('active', true)
            ->where('branch_id', $branchId)
            ->where('inventory_location_id', $inventoryLocationId)
            ->where('cash_drawer_id', $cashDrawerId);
    }
}
