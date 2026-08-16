<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserOperationalAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'default_warehouse_id_snapshot',
        'default_warehouse_name_snapshot',
        'default_cash_drawer_id_snapshot',
        'default_cash_drawer_name_snapshot',
        'temporary_warehouse_id',
        'temporary_warehouse_name_snapshot',
        'temporary_cash_drawer_id',
        'temporary_cash_drawer_name_snapshot',
        'assigned_by_user_id',
        'assigned_by_user_name_snapshot',
        'starts_at',
        'ends_at',
        'reason',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'default_warehouse_id_snapshot' => 'integer',
        'default_cash_drawer_id_snapshot' => 'integer',
        'temporary_warehouse_id' => 'integer',
        'temporary_cash_drawer_id' => 'integer',
        'assigned_by_user_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function temporaryWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'temporary_warehouse_id');
    }

    public function temporaryCashDrawer()
    {
        return $this->belongsTo(CashDrawer::class, 'temporary_cash_drawer_id');
    }
}
