<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit record of a subscription reminder that was sent (or attempted) to a
 * tenant. Doubles as the idempotency guard so the daily command never sends
 * the same reminder twice.
 */
class SubscriptionReminder extends Model
{
    protected $connection = 'central';

    protected $table = 'subscription_reminders';

    public const TYPE_EXPIRY     = 'expiry';
    public const TYPE_TRIAL      = 'trial';
    public const TYPE_EXPIRED    = 'expired';
    public const TYPE_PLAN_ENDED = 'plan_ended';

    public const CHANNEL_EMAIL  = 'email';
    public const CHANNEL_SMS    = 'sms';
    public const CHANNEL_BANNER = 'banner';

    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'type',
        'channel',
        'offset_days',
        'reference_date',
        'target',
        'status',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'offset_days'    => 'integer',
        'reference_date' => 'date',
        'sent_at'        => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function subscription()
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    /**
     * Whether a reminder matching this idempotency key was already delivered
     * successfully. Failed / skipped attempts do NOT block a later retry
     * (the unique index + updateOrCreate keep it to a single row).
     */
    public static function alreadySent(
        ?int $subscriptionId,
        string $type,
        string $channel,
        int $offsetDays,
        $referenceDate
    ): bool {
        return static::where('tenant_subscription_id', $subscriptionId)
            ->where('type', $type)
            ->where('channel', $channel)
            ->where('offset_days', $offsetDays)
            ->whereDate('reference_date', $referenceDate)
            ->where('status', self::STATUS_SENT)
            ->exists();
    }
}
