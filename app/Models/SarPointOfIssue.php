<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SarPointOfIssue extends Model
{
    protected $table = 'sar_points_of_issue';

    protected $fillable = [
        'establishment_code', 'point_code', 'name', 'address',
        'branch_id', 'inventory_location_id', 'cash_drawer_id',
        'warehouse_id', 'active', 'is_auto_managed',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_auto_managed' => 'boolean',
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
     * The cash drawers this point of issue covers. One point can legally cover
     * several drawers of the same branch (they share one correlativo sequence);
     * a drawer maps to at most one point.
     */
    public function cashDrawers()
    {
        return $this->belongsToMany(
            CashDrawer::class,
            'sar_point_cash_drawers',
            'sar_point_of_issue_id',
            'cash_drawer_id'
        )->withTimestamps();
    }

    /** Modern POS resolution: the active point that covers a given cash drawer. */
    public function scopeCoveringDrawer($query, int $cashDrawerId)
    {
        return $query->where('active', true)
            ->whereHas('cashDrawers', fn ($q) => $q->where('cash_drawers.id', $cashDrawerId));
    }

    /** Legacy resolution kept for pre-pivot points. */
    public function scopeForOperationalContext($query, int $branchId, int $inventoryLocationId, int $cashDrawerId)
    {
        return $query->where('active', true)
            ->where('branch_id', $branchId)
            ->where('inventory_location_id', $inventoryLocationId)
            ->where('cash_drawer_id', $cashDrawerId);
    }

    public function hasCodes(): bool
    {
        return filled($this->establishment_code) && filled($this->point_code);
    }
}
