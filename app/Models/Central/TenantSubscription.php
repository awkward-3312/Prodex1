<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Central (SaaS) tenant subscription to a plan — not tenant-scoped.
 */
class TenantSubscription extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_subscriptions';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_TRIAL     = 'trial';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Allowed status transitions. Keys are the source status; values are the
     * statuses that source can move to. Used by transitionTo() to enforce a
     * single chokepoint for admin-driven status changes.
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING   => [self::STATUS_ACTIVE, self::STATUS_TRIAL, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_TRIAL     => [self::STATUS_ACTIVE, self::STATUS_CANCELLED, self::STATUS_EXPIRED],
        self::STATUS_ACTIVE    => [self::STATUS_CANCELLED, self::STATUS_SUSPENDED, self::STATUS_EXPIRED],
        self::STATUS_CANCELLED => [self::STATUS_ACTIVE],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_EXPIRED   => [self::STATUS_ACTIVE],
        self::STATUS_FAILED    => [self::STATUS_PENDING, self::STATUS_ACTIVE],
    ];

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'billing_cycle',
        'amount',
        'currency',
        'status',
        'starts_at',
        'trial_ends_at',
        'ends_at',
        'cancelled_at',
        'cancellation_requested_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'starts_at'     => 'datetime',
        'trial_ends_at' => 'datetime',
        'ends_at'       => 'datetime',
        'cancelled_at'  => 'datetime',
        'cancellation_requested_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(TenantBillingPayment::class, 'tenant_subscription_id');
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return !$this->ends_at || $this->ends_at->isFuture();
        }

        return $this->isOnTrial();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED && $this->ends_at && $this->ends_at->isFuture();
    }

    public function canResume(): bool
    {
        return $this->isCancelled();
    }

    public function isExpired(): bool
    {
        return $this->ends_at && $this->ends_at->isPast() && $this->status !== self::STATUS_ACTIVE;
    }

    public function daysRemaining(): int
    {
        if (!$this->ends_at) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->ends_at, false));
    }

    /**
     * Activate after successful payment (first-time activation).
     */
    public function activate(): void
    {
        $startsAt = now();
        $endsAt = $this->billing_cycle === 'yearly'
            ? $startsAt->copy()->addYear()
            : $startsAt->copy()->addMonth();

        $this->update([
            'status'       => self::STATUS_ACTIVE,
            'starts_at'    => $startsAt,
            'ends_at'      => $endsAt,
            'cancelled_at' => null,
            'cancellation_requested_at' => null,
        ]);

        // This subscription replaces whatever the tenant was on before (free
        // trial, previous plan) — expire those rows so only one is live.
        static::where('tenant_id', $this->tenant_id)
            ->whereKeyNot($this->getKey())
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_TRIAL])
            ->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Extend an already-active subscription by one billing cycle (renewal).
     * If ends_at is still in the future, extends from that date.
     * If ends_at has passed, extends from now.
     */
    public function renew(): void
    {
        $base = ($this->ends_at && $this->ends_at->isFuture())
            ? $this->ends_at->copy()
            : now();

        $newEndsAt = $this->billing_cycle === 'yearly'
            ? $base->addYear()
            : $base->addMonth();

        $this->update([
            'status'       => self::STATUS_ACTIVE,
            'ends_at'      => $newEndsAt,
            'cancelled_at' => null,
            'cancellation_requested_at' => null,
        ]);
    }

    /**
     * Cancel subscription immediately — for confirmed cancellations only:
     * Paddle's subscription.canceled webhook (cancellation already took
     * effect at Paddle), an admin's emergency override, or a gateway with no
     * period-end scheduling support. Status flips right away, so access is
     * cut right away — never call this for a customer's "cancel at period
     * end" request; use markCancellationRequested() for that instead.
     */
    public function cancel(): void
    {
        $this->update([
            'status'       => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_requested_at' => null,
        ]);
    }

    /**
     * Record that the tenant asked to cancel at the end of the current
     * billing period. Status and ends_at are deliberately left untouched —
     * the subscription stays ACTIVE (and access stays granted) until Paddle's
     * subscription.canceled webhook confirms the cancellation actually took
     * effect, at which point cancel() (via the webhook handler) applies it.
     */
    public function markCancellationRequested(): void
    {
        $this->update(['cancellation_requested_at' => now()]);
    }

    /**
     * Undo a pending cancellation request before it has taken effect
     * (resume). Only meaningful while still ACTIVE — a subscription already
     * flipped to CANCELLED must go through resume() instead.
     */
    public function clearCancellationRequest(): void
    {
        $this->update(['cancellation_requested_at' => null]);
    }

    /**
     * True while a cancellation has been requested but Paddle hasn't
     * confirmed it took effect yet — ACTIVE, TRIAL, or SUSPENDED can all
     * carry the flag (Paddle keeps a scheduled cancel through a trial or a
     * dunning-driven past_due/paused state). False once the subscription is
     * actually CANCELLED/EXPIRED/FAILED — the flag no longer means anything
     * once a terminal state has been reached. Access must NOT be cut while
     * this is true.
     */
    public function isPendingCancellation(): bool
    {
        return $this->cancellation_requested_at !== null
            && in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL, self::STATUS_SUSPENDED], true);
    }

    /**
     * Resume a cancelled subscription (only if billing period hasn't expired).
     */
    public function resume(): void
    {
        if (! $this->canResume()) {
            return;
        }

        $this->update([
            'status'       => self::STATUS_ACTIVE,
            'cancelled_at' => null,
            'cancellation_requested_at' => null,
        ]);
    }

    /**
     * Whether moving from the current status to $newStatus is allowed.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->status === $newStatus) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    /**
     * Apply a status transition with extra attributes. Returns false if the
     * transition is not allowed; the caller is responsible for surfacing the
     * error to the user.
     *
     * Resume of a cancelled subscription (cancelled → active) requires that
     * ends_at is still in the future, mirroring canResume().
     */
    public function transitionTo(string $newStatus, array $extra = []): bool
    {
        if (! $this->canTransitionTo($newStatus)) {
            return false;
        }

        if ($this->status === self::STATUS_CANCELLED && $newStatus === self::STATUS_ACTIVE) {
            if (! $this->ends_at || $this->ends_at->isPast()) {
                return false;
            }
            $extra['cancelled_at'] = null;
        }

        if ($newStatus === self::STATUS_ACTIVE) {
            // Reactivating (from any allowed source status) means nothing is
            // pending-cancel any more — a stale flag from a state Paddle has
            // since moved past must not survive the transition.
            $extra['cancellation_requested_at'] = null;
        }

        $this->update(array_merge($extra, ['status' => $newStatus]));

        return true;
    }
}
