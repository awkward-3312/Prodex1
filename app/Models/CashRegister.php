<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'warehouse_id',
        'cash_drawer_id',
        'opening_balance',
        'closing_balance',
        'total_sales',
        'cash_in',
        'cash_out',
        'difference',
        'counted_denominations',
        'sales_by_payment_method',
        'expected_cash',
        'counted_cash',
        'cash_difference',
        'card_system_total',
        'card_terminal_total',
        'card_difference',
        'card_batch_number',
        'card_reference',
        'card_notes',
        'transfer_total',
        'transfers_verified',
        'transfer_notes',
        'cash_withdrawn_at_close',
        'next_opening_float',
        'register_number_snapshot',
        'opened_by_user_id_snapshot',
        'opened_by_user_name_snapshot',
        'closed_by_user_id',
        'closed_by_user_name_snapshot',
        'warehouse_id_snapshot',
        'warehouse_name_snapshot',
        'cash_drawer_name_snapshot',
        'cash_drawer_code_snapshot',
        'tenant_id_snapshot',
        'opened_date_snapshot',
        'opened_time_snapshot',
        'closed_date_snapshot',
        'closed_time_snapshot',
        'session_duration_seconds',
        'closing_status',
        'closing_snapshot',
        'status',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        'cash_drawer_id' => 'integer',
        'opening_balance' => 'double',
        'closing_balance' => 'double',
        'total_sales' => 'double',
        'cash_in' => 'double',
        'cash_out' => 'double',
        'difference' => 'double',
        'counted_denominations' => 'array',
        'sales_by_payment_method' => 'array',
        'expected_cash' => 'double',
        'counted_cash' => 'double',
        'cash_difference' => 'double',
        'card_system_total' => 'double',
        'card_terminal_total' => 'double',
        'card_difference' => 'double',
        'transfer_total' => 'double',
        'transfers_verified' => 'boolean',
        'cash_withdrawn_at_close' => 'double',
        'next_opening_float' => 'double',
        'opened_by_user_id_snapshot' => 'integer',
        'closed_by_user_id' => 'integer',
        'warehouse_id_snapshot' => 'integer',
        'session_duration_seconds' => 'integer',
        'closing_snapshot' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashDrawer()
    {
        return $this->belongsTo(CashDrawer::class);
    }
}
