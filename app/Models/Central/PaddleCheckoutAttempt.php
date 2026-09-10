<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PaddleCheckoutAttempt extends Model
{
    protected $connection = 'central';

    protected $table = 'paddle_checkout_attempts';

    protected $fillable = [
        'reference',
        'tenant_id',
        'plan_id',
        'billing_cycle',
        'status',
        'paddle_subscription_id',
        'expires_at',
        'claimed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
