<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegisterOperation extends Model
{
    protected $fillable = [
        'operation_uuid', 'cash_register_id', 'user_id', 'operation_type',
        'amount', 'notes', 'payload_fingerprint', 'source',
    ];

    protected $casts = [
        'cash_register_id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'double',
    ];

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
