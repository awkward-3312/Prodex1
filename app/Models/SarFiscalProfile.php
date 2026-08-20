<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SarFiscalProfile extends Model
{
    protected $fillable = [
        'enabled', 'rtn', 'legal_name', 'trade_name',
        'head_office_address', 'phone', 'email', 'invoice_settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'invoice_settings' => 'array',
    ];
}
