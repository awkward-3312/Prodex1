<?php

namespace App\Models\Central;

use App\Services\Paddle\PaddlePriceGuard;
use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class TenantBillingPayment extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_billing_payments';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PAID       = 'paid';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_REFUNDED   = 'refunded';
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_SUPERSEDED,
    ];

    public const GATEWAYS = [
        'dlocal'      => 'dLocal',
        'stripe'      => 'Stripe',
        'paypal'      => 'PayPal',
        'paddle'      => 'Paddle',
        'paystack'    => 'Paystack',
        'flutterwave' => 'Flutterwave',
        'mollie'      => 'Mollie',
        'offline'     => 'Bank Transfer',
        'manual'      => 'Manual Payment',
    ];

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'plan_id',
        'amount',
        'tax',
        'currency',
        'gateway_currency',
        'gateway_amount',
        'exchange_rate',
        'conversion_applied',
        'status',
        'gateway',
        'gateway_payment_id',
        'transaction_id',
        'payment_proof_path',
        'billing_cycle',
        'invoice_number',
        'notes',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'tax'                => 'decimal:2',
        'gateway_amount'     => 'decimal:2',
        'exchange_rate'      => 'decimal:8',
        'conversion_applied' => 'boolean',
        'paid_at'            => 'datetime',
        'metadata'           => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            if (empty($payment->invoice_number)) {
                $payment->invoice_number = static::generateInvoiceNumber();
            }

            if ($payment->gateway === 'paddle') {
                $subscription = TenantSubscription::find($payment->tenant_subscription_id);
                if (! $subscription) {
                    throw new RuntimeException('Paddle payment references an unknown PRODEX subscription.');
                }

                $metadata = is_array($payment->metadata) ? $payment->metadata : [];
                $actualPriceId = trim((string) ($metadata['paddle_price_id'] ?? ''));
                $mapping = PaddleSubscription::where('tenant_subscription_id', $subscription->id)->first();

                if ($mapping && trim((string) $mapping->paddle_price_id) !== '') {
                    if ($actualPriceId === '' || ! hash_equals((string) $mapping->paddle_price_id, $actualPriceId)) {
                        throw new RuntimeException('Paddle transaction price does not match its PRODEX subscription mapping.');
                    }
                } else {
                    app(PaddlePriceGuard::class)->assertMatchesSubscription($subscription, $actualPriceId);
                }
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function subscription()
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function markPaid(string $gatewayPaymentId = null, string $transactionId = null): void
    {
        $data = [
            'status'  => self::STATUS_PAID,
            'paid_at' => now(),
        ];

        if ($gatewayPaymentId) {
            $data['gateway_payment_id'] = $gatewayPaymentId;
        }
        if ($transactionId) {
            $data['transaction_id'] = $transactionId;
        }

        $this->update($data);
    }

    public function markFailed(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }

    public function markRefunded(string $refundTransactionId = null): void
    {
        $data = ['status' => self::STATUS_REFUNDED];

        if ($refundTransactionId) {
            $data['notes'] = trim(($this->notes ? $this->notes . "\n" : '') . 'Refund ID: ' . $refundTransactionId);
        }

        $this->update($data);
    }

    public function markSuperseded(?int $bySupersedingId = null): void
    {
        $note = $bySupersedingId
            ? 'Superseded by payment #' . $bySupersedingId
            : 'Superseded';

        $this->update([
            'status' => self::STATUS_SUPERSEDED,
            'notes'  => trim(($this->notes ? $this->notes . "\n" : '') . $note),
        ]);
    }

    public function getGatewayLabelAttribute(): string
    {
        return self::GATEWAYS[$this->gateway] ?? ucfirst($this->gateway ?? 'Unknown');
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID       => 'success',
            self::STATUS_PENDING    => 'warning',
            self::STATUS_FAILED     => 'danger',
            self::STATUS_REFUNDED   => 'info',
            self::STATUS_SUPERSEDED => 'secondary',
            default                 => 'secondary',
        };
    }

    public function getTotalAttribute(): float
    {
        return (float) $this->amount + (float) $this->tax;
    }

    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . date('Ym') . '-';
        $last = static::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
