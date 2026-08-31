<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adjustment extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'user_id', 'warehouse_id', 'inventory_location_id',
        'inventory_effect_snapshot', 'time',
        'items', 'notes', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        // NULL => registro legacy; NOT NULL => flujo location-aware (#81).
        'inventory_location_id' => 'integer',
        // Plan físico EXACTO ya expandido aplicado en el create (para reversa).
        'inventory_effect_snapshot' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\AdjustmentDetail');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }
}
