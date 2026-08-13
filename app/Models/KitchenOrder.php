<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KitchenOrder extends Model
{
    use SoftDeletes;

    /** Allowed kitchen statuses. */
    public const STATUSES = ['pending', 'preparing', 'completed', 'on_hold'];

    protected $fillable = [
        'sale_id', 'ref', 'client_id', 'warehouse_id', 'dispatched_warehouse_id', 'user_id', 'assigned_to',
        'status', 'instructions', 'sent_at', 'started_at', 'completed_at', 'dispatched_at',
    ];

    protected $casts = [
        'sale_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'dispatched_warehouse_id' => 'integer',
        'user_id' => 'integer',
        'assigned_to' => 'integer',
        'sent_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function dispatchedWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'dispatched_warehouse_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The salesperson who sent the order to the kitchen. */
    public function sentBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
