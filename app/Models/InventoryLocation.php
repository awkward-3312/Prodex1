<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class InventoryLocation extends Model
{
    use SoftDeletes;

    public const TYPE_SALES_FLOOR = 'sales_floor';
    public const TYPE_STORAGE = 'storage';
    public const TYPE_QUARANTINE = 'quarantine';
    public const TYPE_DAMAGED = 'damaged';
    public const TYPE_RETURNS = 'returns';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_SALES_FLOOR,
        self::TYPE_STORAGE,
        self::TYPE_QUARANTINE,
        self::TYPE_DAMAGED,
        self::TYPE_RETURNS,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'branch_id',
        'warehouse_id',
        'code',
        'name',
        'type',
        'is_sellable',
        'is_default_sales',
        'is_quarantine',
        'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'is_sellable' => 'boolean',
        'is_default_sales' => 'boolean',
        'is_quarantine' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (InventoryLocation $location) {
            $hasBranch = ! empty($location->branch_id);
            $hasWarehouse = ! empty($location->warehouse_id);

            if ($hasBranch === $hasWarehouse) {
                throw ValidationException::withMessages([
                    'owner' => 'La ubicación de inventario debe pertenecer a una sucursal o a un almacén/CD, pero no a ambos.',
                ]);
            }

            if (! in_array($location->type, self::TYPES, true)) {
                throw ValidationException::withMessages([
                    'type' => 'El tipo de ubicación de inventario no es válido.',
                ]);
            }

            if ($location->type === self::TYPE_QUARANTINE) {
                $location->is_quarantine = true;
                $location->is_sellable = false;
            }

            if ($location->is_default_sales) {
                $location->is_sellable = true;
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at')->where('is_active', true);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function getOwnerTypeAttribute(): string
    {
        return $this->branch_id ? 'branch' : 'warehouse';
    }
}
