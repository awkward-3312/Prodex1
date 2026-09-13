<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaddleCheckoutAttempt;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\PaddleWebhookEvent;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Paddle\PaddleCheckoutReference;
use App\Services\Paddle\PaddleWebhookVerifier;
use App\Support\LocksBillingSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaddleWebhookController extends Controller
{
    use LocksBillingSubscription;

    public function handle(
        Request $request,
        PaddleWebhookVerifier $verifier,
        PaddleCheckoutReference $references,
        SubscriptionLifecycleService $lifecycle
    ) {
        $secret = trim((string) config('services.paddle.webhook_secret', ''));
        if ($secret === '') {
            Log::error('Paddle webhook received but webhook_secret is not configured.');
            return response('Paddle webhook is not configured.', 503);
        }

        // Paddle signs the exact raw body. Never verify a decoded/re-encoded JSON
        // representation because whitespace/key-order changes invalidate the HMAC.
        $rawBody = $request->getContent();
        $signature = (string) $request->header('Paddle-Signature', '');
        $tolerance = max(5, (int) config('services.paddle.webhook_tolerance', 300));

        if (! $verifier->verify($rawBody, $signature, $secret, $tolerance)) {
            Log::warning('Paddle webhook signature verification failed.');
            return response('Invalid signature.', 400);
        }

        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response('Invalid JSON.', 400);
        }

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $eventType = trim((string) ($payload['event_type'] ?? ''));
        $data = $payload['data'] ?? null;

        if ($eventId === '' || $eventType === '' || ! is_array($data)) {
            return response('Invalid event.', 400);
        }

        $occurredAt = $this->parseDate($payload['occurred_at'] ?? null);
        $payloadHash = hash('sha256', $rawBody);

        $event = PaddleWebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
            ]
        );

        if (! $event->wasRecentlyCreated) {
            if (! hash_equals((string) $event->payload_hash, $payloadHash)) {
                Log::error("Paddle event {$eventId} was replayed with a different payload.");
                return response('Event payload mismatch.', 400);
            }

            if ($event->status === 'processed') {
                return response('OK', 200);
            }

            $event->update([
                'status' => 'processing',
                'error' => null,
            ]);
        }

        try {
            DB::connection('central')->transaction(function () use (
                $eventType,
                $eventId,
                $data,
                $occurredAt,
                $references,
                $lifecycle
            ) {
                $this->dispatchEvent($eventType, $eventId, $data, $occurredAt, $references, $lifecycle);
            });

            $event->update([
                'status' => 'processed',
                'processed_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error("Paddle webhook {$eventId} ({$eventType}) failed: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            // Paddle retries destinations that return a non-2xx response.
            return response('Webhook processing failed.', 500);
        }

        return response('OK', 200);
    }

    private function dispatchEvent(
        string $eventType,
        string $eventId,
        array $data,
        ?Carbon $occurredAt,
        PaddleCheckoutReference $references,
        SubscriptionLifecycleService $lifecycle
    ): void {
        if (str_starts_with($eventType, 'subscription.')) {
            $this->syncSubscription($data, $occurredAt, $references);
            return;
        }

        if ($eventType === 'transaction.completed') {
            $this->handleCompletedTransaction($eventId, $data, $references, $lifecycle);
            return;
        }

        if (in_array($eventType, ['transaction.payment_failed', 'transaction.past_due'], true)) {
            $this->handleFailedTransaction($eventId, $data, $references, $lifecycle);
            return;
        }

        if (in_array($eventType, ['adjustment.created', 'adjustment.updated'], true)) {
            $this->handleAdjustment($data, $lifecycle);
        }
    }

    private function syncSubscription(array $data, ?Carbon $occurredAt, PaddleCheckoutReference $references): void
    {
        $paddleSubscriptionId = trim((string) ($data['id'] ?? ''));
        if ($paddleSubscriptionId === '' || ! str_starts_with($paddleSubscriptionId, 'sub_')) {
            throw new RuntimeException('Paddle subscription event has no valid subscription ID.');
        }

        $subscription = $this->resolveSubscription($paddleSubscriptionId, $data, $references);
        if (! $subscription) {
            throw new RuntimeException('Paddle subscription cannot be linked to an authenticated PRODEX checkout.');
        }

        // Same lock namespace as BillingApiController/TenantSubscriptionController
        // ('billing:cancel:{subscription id}') — a customer's cancel/resume
        // click makes a live Paddle API call before writing locally, and this
        // webhook must not read-modify-write the same row while that's in
        // flight, or one write silently clobbers the other.
        $this->withSubscriptionLock($subscription, function () use ($subscription, $paddleSubscriptionId, $occurredAt, $data): void {
                $mapping = PaddleSubscription::where('paddle_subscription_id', $paddleSubscriptionId)->firstOrFail();

                // Paddle can deliver events out of order. Only subscription
                // lifecycle events use this timestamp guard; transaction IDs
                // are independently idempotent and must still be recorded.
                if ($mapping->last_event_at && $occurredAt && $occurredAt->lt($mapping->last_event_at)) {
                    Log::info("Ignoring stale Paddle subscription event for {$mapping->paddle_subscription_id}.");
                    return;
                }

                $period = is_array($data['current_billing_period'] ?? null) ? $data['current_billing_period'] : [];
                $periodStartsAt = $this->parseDate($period['starts_at'] ?? null);
                $periodEndsAt = $this->parseDate($period['ends_at'] ?? null);
                $nextBilledAt = $this->parseDate($data['next_billed_at'] ?? null);
                $status = strtolower((string) ($data['status'] ?? ''));
                $scheduledChange = is_array($data['scheduled_change'] ?? null) ? $data['scheduled_change'] : null;

                $mapping->update([
                    'paddle_customer_id' => $data['customer_id'] ?? $mapping->paddle_customer_id,
                    'paddle_price_id' => $this->extractPriceId($data) ?: $mapping->paddle_price_id,
                    'status' => $status !== '' ? $status : $mapping->status,
                    'next_billed_at' => $nextBilledAt,
                    'current_period_starts_at' => $periodStartsAt,
                    'current_period_ends_at' => $periodEndsAt,
                    'scheduled_change' => $scheduledChange,
                    'custom_data' => is_array($data['custom_data'] ?? null) ? $data['custom_data'] : $mapping->custom_data,
                    'last_event_at' => $occurredAt ?: now(),
                ]);

                $this->applySubscriptionStatus(
                    $subscription,
                    $data,
                    $status,
                    $periodStartsAt,
                    $periodEndsAt,
                    $nextBilledAt,
                    $occurredAt,
                    $scheduledChange
                );
        });
    }

    /**
     * Paddle retries destinations that return a non-2xx response — asking
     * it to redeliver later is exactly right here rather than racing the
     * in-flight customer/admin request holding the lock.
     */
    private function onSubscriptionLockTimeout(): never
    {
        throw new RuntimeException('Could not acquire subscription lock; a concurrent billing action is in progress.');
    }

    private function applySubscriptionStatus(
        TenantSubscription $subscription,
        array $data,
        string $status,
        ?Carbon $periodStartsAt,
        ?Carbon $periodEndsAt,
        ?Carbon $nextBilledAt,
        ?Carbon $occurredAt,
        ?array $scheduledChange = null
    ): void {
        $startedAt = $this->parseDate($data['started_at'] ?? null)
            ?: $periodStartsAt
            ?: $subscription->starts_at
            ?: now();

        // A scheduled CANCEL means the local "pending cancellation" flag must
        // be set, whatever the subscription's status is — established if
        // PRODEX didn't already know about it (e.g. the tenant cancelled
        // through Paddle's own customer portal), preserved if it did. No
        // scheduled_change, or one for something else (e.g. a scheduled
        // pause), means Paddle no longer has a cancellation scheduled — clear
        // a stale flag so it never disagrees with Paddle's authoritative
        // state. Applied the same way across every non-terminal status
        // branch so it can't drift out of sync at a call site that forgets it.
        $cancellationRequestedAt = ($scheduledChange['action'] ?? null) === 'cancel'
            ? ($subscription->cancellation_requested_at ?: ($occurredAt ?: now()))
            : null;

        if ($status === 'trialing') {
            $trialEndsAt = $nextBilledAt ?: $periodEndsAt;
            if (! $trialEndsAt) {
                $trialDays = max(1, (int) ($subscription->plan?->trial_days ?? 30));
                $trialEndsAt = now()->addDays($trialDays);
            }

            $subscription->update([
                'status' => TenantSubscription::STATUS_TRIAL,
                'starts_at' => $startedAt,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => $trialEndsAt,
                'cancelled_at' => null,
                'cancellation_requested_at' => $cancellationRequestedAt,
            ]);
            $this->expireOtherLiveSubscriptions($subscription);
            return;
        }

        if ($status === 'active') {
            $endsAt = $periodEndsAt ?: $nextBilledAt ?: $this->fallbackPeriodEnd($subscription);
            $subscription->update([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => $subscription->starts_at ?: $startedAt,
                'trial_ends_at' => null,
                'ends_at' => $endsAt,
                'cancelled_at' => null,
                'cancellation_requested_at' => $cancellationRequestedAt,
            ]);
            $this->expireOtherLiveSubscriptions($subscription);
            return;
        }

        if (in_array($status, ['past_due', 'paused'], true)) {
            $subscription->update([
                'status' => TenantSubscription::STATUS_SUSPENDED,
                'ends_at' => $periodEndsAt ?: $subscription->ends_at,
                'cancellation_requested_at' => $cancellationRequestedAt,
            ]);
            return;
        }

        if ($status === 'canceled') {
            $cancelledAt = $this->parseDate($data['canceled_at'] ?? null) ?: $occurredAt ?: now();
            $subscription->update([
                'status' => TenantSubscription::STATUS_CANCELLED,
                'ends_at' => $periodEndsAt ?: $cancelledAt,
                'cancelled_at' => $cancelledAt,
                // The cancellation Paddle just confirmed supersedes any
                // locally-tracked "pending" request.
                'cancellation_requested_at' => null,
            ]);
        }
    }

    private function handleCompletedTransaction(
        string $eventId,
        array $data,
        PaddleCheckoutReference $references,
        SubscriptionLifecycleService $lifecycle
    ): void {
        $transactionId = trim((string) ($data['id'] ?? ''));
        if ($transactionId === '' || ! str_starts_with($transactionId, 'txn_')) {
            throw new RuntimeException('Paddle transaction.completed has no valid transaction ID.');
        }

        $paddleSubscriptionId = trim((string) ($data['subscription_id'] ?? ''));
        if ($paddleSubscriptionId === '') {
            // PRODEX currently sends only recurring Paddle prices, so a completed
            // transaction without a subscription is not one of our SaaS checkouts.
            throw new RuntimeException("Paddle transaction {$transactionId} has no subscription ID.");
        }

        $subscription = $this->resolveSubscription($paddleSubscriptionId, $data, $references);
        if (! $subscription) {
            throw new RuntimeException("Paddle transaction {$transactionId} cannot be linked to PRODEX.");
        }

        $gatewayAmount = $this->transactionTotal($data);
        if ($gatewayAmount <= 0) {
            // A free-trial checkout can complete with zero due today. Access is
            // granted by subscription.trialing; no paid invoice is fabricated.
            Log::info("Paddle transaction {$transactionId} completed with zero total; payment record skipped.");
            return;
        }

        $payment = $this->findOrCreateTransactionPayment($eventId, $data, $subscription, $gatewayAmount);
        $lifecycle->markProviderPaid($payment, [
            'gateway_payment_id' => $transactionId,
            'transaction_id' => $transactionId,
        ]);

        // transaction.completed proves money was captured. If the corresponding
        // subscription event is delayed, keep access available now; the next
        // subscription webhook replaces these fallback dates with Paddle's exact
        // billing period and does not double-extend it.
        if (! $subscription->isActive()) {
            $period = is_array($data['billing_period'] ?? null) ? $data['billing_period'] : [];
            $startsAt = $this->parseDate($period['starts_at'] ?? null) ?: now();
            $endsAt = $this->parseDate($period['ends_at'] ?? null)
                ?: $this->fallbackPeriodEnd($subscription, $startsAt);

            $subscription->update([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'trial_ends_at' => null,
                'ends_at' => $endsAt,
                'cancelled_at' => null,
                // A captured charge proves the subscription actually renewed
                // instead of cancelling — any pending-cancellation flag is
                // now stale.
                'cancellation_requested_at' => null,
            ]);
            $this->expireOtherLiveSubscriptions($subscription);
        }
    }

    private function handleFailedTransaction(
        string $eventId,
        array $data,
        PaddleCheckoutReference $references,
        SubscriptionLifecycleService $lifecycle
    ): void {
        $transactionId = trim((string) ($data['id'] ?? ''));
        $paddleSubscriptionId = trim((string) ($data['subscription_id'] ?? ''));

        if ($transactionId === '' || $paddleSubscriptionId === '') {
            return;
        }

        $subscription = $this->resolveSubscription($paddleSubscriptionId, $data, $references);
        if (! $subscription) {
            Log::warning("Paddle failed transaction {$transactionId} could not be linked to PRODEX.");
            return;
        }

        $payment = $this->findOrCreateTransactionPayment(
            $eventId,
            $data,
            $subscription,
            $this->transactionTotal($data)
        );
        $lifecycle->markFailed($payment);
    }

    private function handleAdjustment(array $data, SubscriptionLifecycleService $lifecycle): void
    {
        $action = strtolower((string) ($data['action'] ?? ''));
        $status = strtolower((string) ($data['status'] ?? ''));
        $transactionId = trim((string) ($data['transaction_id'] ?? ''));

        if (! in_array($action, ['refund', 'chargeback'], true)
            || $status !== 'approved'
            || $transactionId === ''
        ) {
            return;
        }

        $payment = TenantBillingPayment::where('gateway', 'paddle')
            ->where(function ($query) use ($transactionId) {
                $query->where('gateway_payment_id', $transactionId)
                    ->orWhere('transaction_id', $transactionId);
            })
            ->first();

        if (! $payment) {
            Log::warning("Paddle adjustment could not find transaction {$transactionId} in PRODEX.");
            return;
        }

        $adjustmentId = trim((string) ($data['id'] ?? '')) ?: null;
        $type = strtolower((string) ($data['type'] ?? ''));

        if ($type === 'full' || $action === 'chargeback') {
            $lifecycle->markRefunded($payment, $adjustmentId);

            // A chargeback is stronger than an ordinary refund: suspend access
            // immediately while Paddle's subscription lifecycle catches up.
            if ($action === 'chargeback' && $payment->subscription) {
                $payment->subscription->update([
                    'status' => TenantSubscription::STATUS_SUSPENDED,
                ]);
            }
            return;
        }

        $metadata = $payment->metadata ?? [];
        $metadata['paddle_adjustments'] = array_values(array_unique(array_filter(array_merge(
            (array) ($metadata['paddle_adjustments'] ?? []),
            [$adjustmentId]
        ))));

        $payment->update([
            'metadata' => $metadata,
            'notes' => trim(
                ($payment->notes ? $payment->notes."\n" : '')
                .'Partial Paddle refund: '.($adjustmentId ?: 'unknown')
            ),
        ]);
    }

    /**
     * Resolve (or, for the first verified event, create) the local subscription
     * that belongs to a Paddle subscription. The browser never supplies tenant
     * IDs directly: custom_data contains only a signed random checkout reference.
     */
    private function resolveSubscription(
        string $paddleSubscriptionId,
        array $data,
        PaddleCheckoutReference $references
    ): ?TenantSubscription {
        $mapping = PaddleSubscription::where('paddle_subscription_id', $paddleSubscriptionId)->first();
        $attempt = $this->attemptFromData($data, $references);

        if ($mapping?->subscription) {
            if ($attempt) {
                $this->assertAttemptMatchesSubscription($attempt, $mapping->subscription, $paddleSubscriptionId);
            }
            return $mapping->subscription;
        }

        if (! $attempt) {
            return null;
        }

        if ($attempt->paddle_subscription_id
            && $attempt->paddle_subscription_id !== $paddleSubscriptionId
        ) {
            throw new RuntimeException('Paddle checkout reference is already claimed by another subscription.');
        }

        $subscription = $attempt->subscription;
        if ($subscription) {
            $this->assertAttemptMatchesSubscription($attempt, $subscription, $paddleSubscriptionId);
        } else {
            $plan = Plan::find($attempt->plan_id);
            if (! $plan || ! $plan->is_active) {
                throw new RuntimeException('The Paddle checkout references an unavailable PRODEX plan.');
            }

            $subscription = TenantSubscription::create([
                'tenant_id' => $attempt->tenant_id,
                'plan_id' => $plan->id,
                'billing_cycle' => $attempt->billing_cycle,
                'amount' => $plan->getPriceForCycle($attempt->billing_cycle),
                'currency' => GeneralSetting::currencyCode(),
                'status' => TenantSubscription::STATUS_PENDING,
                'starts_at' => now(),
            ]);
        }

        $existingForLocal = PaddleSubscription::forSubscription($subscription);
        if ($existingForLocal && $existingForLocal->paddle_subscription_id !== $paddleSubscriptionId) {
            throw new RuntimeException('PRODEX subscription is already linked to another Paddle subscription.');
        }

        if (! $existingForLocal) {
            PaddleSubscription::create([
                'tenant_id' => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'paddle_subscription_id' => $paddleSubscriptionId,
                'paddle_customer_id' => $data['customer_id'] ?? null,
                'paddle_price_id' => $this->extractPriceId($data),
                'status' => (string) ($data['status'] ?? 'unknown'),
                'custom_data' => is_array($data['custom_data'] ?? null) ? $data['custom_data'] : null,
            ]);
        }

        $attempt->update([
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => $paddleSubscriptionId,
            'status' => 'claimed',
            'claimed_at' => $attempt->claimed_at ?: now(),
        ]);

        return $subscription;
    }

    private function attemptFromData(array $data, PaddleCheckoutReference $references): ?PaddleCheckoutAttempt
    {
        $customData = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];
        $reference = $references->parse($customData['prodex_ref'] ?? null);

        if (! $reference) {
            return null;
        }

        // The enclosing central transaction makes this lock effective and avoids
        // two first-delivery webhooks claiming the same checkout at once.
        return PaddleCheckoutAttempt::where('reference', $reference)->lockForUpdate()->first();
    }

    private function assertAttemptMatchesSubscription(
        PaddleCheckoutAttempt $attempt,
        TenantSubscription $subscription,
        string $paddleSubscriptionId
    ): void {
        if ((string) $subscription->tenant_id !== (string) $attempt->tenant_id
            || (int) $subscription->plan_id !== (int) $attempt->plan_id
            || (string) $subscription->billing_cycle !== (string) $attempt->billing_cycle
        ) {
            throw new RuntimeException('Paddle checkout reference does not match the mapped PRODEX subscription.');
        }

        if ($attempt->tenant_subscription_id
            && (int) $attempt->tenant_subscription_id !== (int) $subscription->id
        ) {
            throw new RuntimeException('Paddle checkout reference is linked to a different PRODEX subscription.');
        }

        if ($attempt->paddle_subscription_id
            && $attempt->paddle_subscription_id !== $paddleSubscriptionId
        ) {
            throw new RuntimeException('Paddle checkout reference is linked to a different Paddle subscription.');
        }
    }

    private function findOrCreateTransactionPayment(
        string $eventId,
        array $data,
        TenantSubscription $subscription,
        float $gatewayAmount
    ): TenantBillingPayment {
        $transactionId = (string) $data['id'];
        $currency = strtoupper((string) ($data['currency_code'] ?? 'USD'));

        return TenantBillingPayment::firstOrCreate(
            [
                'gateway' => 'paddle',
                'gateway_payment_id' => $transactionId,
            ],
            [
                'tenant_id' => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'gateway_currency' => $currency,
                'gateway_amount' => $gatewayAmount,
                'conversion_applied' => strtoupper((string) $subscription->currency) !== $currency,
                'status' => TenantBillingPayment::STATUS_PENDING,
                'transaction_id' => $transactionId,
                'billing_cycle' => $subscription->billing_cycle,
                'metadata' => [
                    'paddle_event_id' => $eventId,
                    'paddle_subscription_id' => $data['subscription_id'] ?? null,
                    'paddle_customer_id' => $data['customer_id'] ?? null,
                    'paddle_origin' => $data['origin'] ?? null,
                    'paddle_invoice_number' => $data['invoice_number'] ?? null,
                    'paddle_price_id' => $this->extractPriceId($data),
                ],
            ]
        );
    }

    private function extractPriceId(array $data): ?string
    {
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $priceId = $item['price_id'] ?? ($item['price']['id'] ?? null);
            if (is_string($priceId) && str_starts_with($priceId, 'pri_')) {
                return $priceId;
            }
        }

        foreach ((array) ($data['details']['line_items'] ?? []) as $lineItem) {
            $priceId = is_array($lineItem) ? ($lineItem['price_id'] ?? null) : null;
            if (is_string($priceId) && str_starts_with($priceId, 'pri_')) {
                return $priceId;
            }
        }

        return null;
    }

    private function transactionTotal(array $data): float
    {
        $minor = $data['details']['totals']['total']
            ?? $data['details']['totals']['grand_total']
            ?? null;

        if (! is_numeric($minor)) {
            return 0.0;
        }

        // PRODEX currently configures Paddle in USD. Keep the zero-decimal list
        // correct so this helper remains safe if the catalog expands later.
        $currency = strtoupper((string) ($data['currency_code'] ?? 'USD'));
        $zeroDecimal = in_array($currency, ['JPY', 'KRW', 'VND'], true);

        return (float) $minor / ($zeroDecimal ? 1 : 100);
    }

    private function expireOtherLiveSubscriptions(TenantSubscription $subscription): void
    {
        TenantSubscription::where('tenant_id', $subscription->tenant_id)
            ->whereKeyNot($subscription->getKey())
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->update(['status' => TenantSubscription::STATUS_EXPIRED]);
    }

    private function fallbackPeriodEnd(TenantSubscription $subscription, ?Carbon $from = null): Carbon
    {
        $from = $from ?: now();

        return $subscription->billing_cycle === 'yearly'
            ? $from->copy()->addYear()
            : $from->copy()->addMonth();
    }

    private function parseDate($value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
