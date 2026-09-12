<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\PaddleApiException;
use App\Exceptions\SubscriptionNotResumableException;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use App\Services\Paddle\PaddleSubscriptionApi;
use Illuminate\Support\Facades\Log;

/**
 * Owns what "cancel" actually means for a subscription. Before this class
 * existed, cancelling only ever flipped local status — Paddle was never
 * notified, so a subscription PRODEX considered cancelled would still be
 * billed at the next renewal. This is the only place that decides between:
 *
 *   - schedule at period end (customer self-service): ask Paddle to stop the
 *     next renewal, but keep the subscription ACTIVE (and access granted)
 *     until Paddle's subscription.canceled webhook confirms it actually
 *     happened — that webhook is what flips local status, not this class.
 *   - immediate (admin override / confirmed provider-side event): revoke
 *     access now regardless of what Paddle says.
 *
 * A subscription with no Paddle mapping (another gateway, manual/offline)
 * keeps the pre-existing immediate-cancel behavior untouched.
 */
class SubscriptionCancellationService
{
    public function __construct(private PaddleSubscriptionApi $paddleApi)
    {
    }

    /**
     * Customer-initiated "cancel my subscription" — access must survive
     * until ends_at.
     *
     * @throws PaddleApiException if Paddle is mapped but the call fails; the
     *                             subscription is left untouched so the
     *                             customer is not told "cancelled" when
     *                             Paddle never actually agreed to it.
     */
    public function scheduleCancellationAtPeriodEnd(TenantSubscription $subscription): void
    {
        $mapping = $this->paddleMapping($subscription);

        if (! $mapping) {
            $subscription->cancel();

            return;
        }

        $response = $this->paddleApi->cancel($mapping->paddle_subscription_id, 'next_billing_period');
        $this->syncMappingFromPaddleResponse($mapping, $response);
        $subscription->markCancellationRequested();
    }

    /**
     * Admin emergency override / confirmed provider-side cancellation.
     * Best-effort against Paddle: an unreachable Paddle API must not block a
     * super-admin from revoking local access, so failures are logged, not
     * thrown.
     */
    public function cancelImmediately(TenantSubscription $subscription): void
    {
        $mapping = $this->paddleMapping($subscription);

        if ($mapping) {
            try {
                $response = $this->paddleApi->cancel($mapping->paddle_subscription_id, 'immediately');
                $this->syncMappingFromPaddleResponse($mapping, $response);
            } catch (PaddleApiException $e) {
                Log::warning('Immediate cancel: Paddle API call failed, proceeding with local-only cancellation.', [
                    'tenant_subscription_id' => $subscription->id,
                    'paddle_subscription_id' => $mapping->paddle_subscription_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $subscription->cancel();
    }

    /**
     * Undo a cancellation before it has taken effect. If it was only
     * scheduled at Paddle (still ACTIVE locally), remove the schedule at
     * Paddle and clear the local flag. Otherwise fall back to the legacy
     * resume() path for a subscription already actually cancelled.
     *
     * @throws PaddleApiException if Paddle is mapped but the call fails; the
     *                             local flag is left set so the customer is
     *                             not told "resumed" when Paddle still has it
     *                             scheduled to cancel.
     */
    public function resumeScheduledCancellation(TenantSubscription $subscription): void
    {
        if ($subscription->isPendingCancellation()) {
            $mapping = $this->paddleMapping($subscription);

            if ($mapping) {
                $response = $this->paddleApi->removeScheduledCancellation($mapping->paddle_subscription_id);
                $this->syncMappingFromPaddleResponse($mapping, $response);
            } else {
                // No Paddle mapping to undo anything against — this only
                // happens if the mapping row was lost after the schedule was
                // created (data fix, migration). Clearing the local flag
                // without telling Paddle can't be helped here, but it must
                // be traceable rather than silently reported as a success.
                Log::warning('Resuming a pending cancellation with no Paddle mapping found; Paddle was not notified.', [
                    'tenant_subscription_id' => $subscription->id,
                ]);
            }

            $subscription->clearCancellationRequest();

            return;
        }

        // Already actually cancelled (not just scheduled) AND Paddle-mapped.
        // Paddle has no "un-cancel" operation for a terminated subscription —
        // a Paddle-mapped subscription in this state must never be
        // reactivated locally with no counterpart at Paddle; the tenant has
        // to subscribe again instead. The explicit status check (rather than
        // just "not pending") matters: a merely SUSPENDED subscription that
        // was never scheduled to cancel must fall through to the legacy
        // resume() below, not be refused here.
        if ($subscription->status === TenantSubscription::STATUS_CANCELLED && $this->paddleMapping($subscription)) {
            throw new SubscriptionNotResumableException(
                'This subscription was already cancelled at Paddle and cannot be resumed.'
            );
        }

        $subscription->resume();
    }

    private function paddleMapping(TenantSubscription $subscription): ?PaddleSubscription
    {
        return PaddleSubscription::forSubscription($subscription);
    }

    /**
     * Cache Paddle's response onto the local mapping row immediately,
     * instead of leaving it stale until the next webhook delivery — the
     * mapping is read directly (e.g. by other admin views) between now and
     * whenever that webhook arrives.
     */
    private function syncMappingFromPaddleResponse(PaddleSubscription $mapping, array $response): void
    {
        $updates = [];

        if (array_key_exists('status', $response) && is_string($response['status']) && $response['status'] !== '') {
            $updates['status'] = $response['status'];
        }

        if (array_key_exists('scheduled_change', $response)) {
            $updates['scheduled_change'] = is_array($response['scheduled_change']) ? $response['scheduled_change'] : null;
        }

        if ($updates !== []) {
            $mapping->update($updates);
        }
    }
}
