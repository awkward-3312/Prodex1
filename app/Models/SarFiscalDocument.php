<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SarFiscalDocument extends Model
{
    protected $fillable = [
        'sale_id', 'authorization_id', 'sequence', 'fiscal_number', 'cai',
        'deadline', 'status', 'issued_at', 'voided_at', 'void_reason',
        'voided_by', 'issuer_snapshot', 'customer_snapshot', 'sale_snapshot',
    ];

    protected $casts = [
        'deadline' => 'date',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
        'issuer_snapshot' => 'array',
        'customer_snapshot' => 'array',
        'sale_snapshot' => 'array',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function authorization()
    {
        return $this->belongsTo(SarAuthorization::class, 'authorization_id');
    }
}
