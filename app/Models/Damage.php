<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Damage extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'user_id', 'warehouse_id', 'inventory_location_id',
        'inventory_effect_snapshot', 'time',
        'items', 'notes', 'source_type', 'source_id', 'transfer_id', 'source_locked',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        // NULL => registro legacy; NOT NULL => flujo location-aware (#81).
        'inventory_location_id' => 'integer',
        // Plan físico EXACTO ya expandido aplicado en el create (para reversa).
        'inventory_effect_snapshot' => 'array',
        'source_id' => 'integer',
        'transfer_id' => 'integer',
        'source_locked' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (Damage $damage) {
            if ((bool) $damage->getOriginal('source_locked')) {
                throw ValidationException::withMessages([
                    'damage' => 'Este daño fue generado por una operación logística y no puede editarse manualmente.',
                ]);
            }
        });

        static::deleting(function (Damage $damage) {
            if ((bool) $damage->getOriginal('source_locked')) {
                throw ValidationException::withMessages([
                    'damage' => 'Este daño fue generado por una operación logística y no puede eliminarse manualmente.',
                ]);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\DamageDetail');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }
}
