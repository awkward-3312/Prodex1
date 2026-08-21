<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransitionState extends Model
{
    public const MODE_LEGACY_ONLY = 'legacy_only';
    public const MODE_SHADOW_COMPARE = 'shadow_compare';
    public const MODE_DUAL_WRITE = 'dual_write';
    public const MODE_LOCATION_PRIMARY = 'location_primary';

    public const MODES = [
        self::MODE_LEGACY_ONLY,
        self::MODE_SHADOW_COMPARE,
        self::MODE_DUAL_WRITE,
        self::MODE_LOCATION_PRIMARY,
    ];

    protected $fillable = [
        'warehouse_id',
        'inventory_location_id',
        'mode',
        'status',
        'mismatch_count',
        'last_audited_at',
        'last_reconciled_at',
        'shadow_enabled_at',
        'metadata',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'inventory_location_id' => 'integer',
        'mismatch_count' => 'integer',
        'last_audited_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'shadow_enabled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
