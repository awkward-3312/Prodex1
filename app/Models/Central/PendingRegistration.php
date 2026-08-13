<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PendingRegistration extends Model
{
    protected $connection = 'central';

    protected $table = 'pending_registrations';

    public const STATUS_PENDING     = 'pending';
    public const STATUS_PROCESSING  = 'processing';
    public const STATUS_PAID        = 'paid';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_EXPIRED     = 'expired';
    public const STATUS_FAILED      = 'failed';

    protected $fillable = [
        'token',
        'subdomain',
        'company_name',
        'admin_email',
        'admin_password_hash',
        'plan_id',
        'billing_cycle',
        'amount',
        'currency',
        'gateway_currency',
        'gateway_amount',
        'exchange_rate',
        'conversion_applied',
        'status',
        'gateway',
        'gateway_session_id',
        'gateway_payment_id',
        'transaction_id',
        'payment_proof_path',
        'paid_at',
        'expires_at',
        'tenant_id',
        'metadata',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'gateway_amount'     => 'decimal:2',
        'exchange_rate'      => 'decimal:8',
        'conversion_applied' => 'boolean',
        'paid_at'            => 'datetime',
        'expires_at'         => 'datetime',
        'metadata'           => 'array',
    ];

    protected $hidden = [
        'admin_password_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $reg) {
            if (empty($reg->token)) {
                $reg->token = Str::random(48);
            }
            if (empty($reg->expires_at)) {
                $reg->expires_at = now()->addHours(24);
            }
        });
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast() && $this->isPending();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }
}
