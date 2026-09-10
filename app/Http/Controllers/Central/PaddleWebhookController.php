<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\PaddleWebhookEvent;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Paddle\PaddleCheckoutReference;
use App\Services\Paddle\PaddleWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaddleWebhookController extends Controller
{
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

            // A non-2xx response makes Paddle retry the event.
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

        $mapping = PaddleSubscription::where('paddle_subscription_id', $paddleSubscriptionId)->first();
        $claims = $this->claimsFromData($data, $references);

        if ($mapping) {
            $subscription = $mapping->subscription;
            if (! $subscription) {
                throw new RuntimeException('Mapped PRODEX subscription no longer exists.');
            }

            if ($claims && ! $this->claimsMatchSubscription($claims, $subscription)) {
                throw new RuntimeException('Paddle custom_data does not match the existing PRODEX subscription mapping.');
            }
        } else {
            $subscription = $this->subscriptionFromClaims($claims);
            if (! $subscription) {
                throw new RuntimeException('Paddle subscription cannot be linked to an authenticated PRODEX checkout.');
            }

            $existingForLocal = PaddleSubscription::where('tenant_subscription_id', $subscription->id)->first();
            if ($existingForLocal && $existingForLocal->paddle_subscription_id !== $paddleSubscriptionId) {
                throw new RuntimeException('PRODEX subscription is already linked to another Paddle subscription.');
            }

            $mapping = $existingForLocal ?: PaddleSubscription::create([
                'tenant_id' => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'paddle_subscription_id' => $paddleSubscriptionId,
                'status' => (string) ($data['status'] ?? 'unknown'),
            ]);
        }

        if ($mapping->last_event_at && $occurredAt && $occurredAt->lt($mapping->last_event_at)) {
            Log::info("Ignoring stale Paddle subscription event for {$paddleSubscriptionId}.");
            return;
        }

        $period = is_array($data['current_billing_period'] ?? null) ? $data['current_billing_period'] : [];
        $periodStartsAt = $this->parseDate($period['starts_at'] ?? null);
        $periodEndsAt = $this->parseDate($period['ends_at'] ?? null);
        $nextBilledAt = $this->parseDate($data['next_billed_at'] ?? null);
        $status = strtolower((string) ($data['status'] ?? ''));

        $mapping->update([
            'paddle_customer_id' => $data['customer_id'] ?? $mapping->paddle_customer_id,
            'paddle_price_id' => $this->extractPriceId($data) ?: $mapping->paddle_price_id,
            'status' => $status !== '' ? $status : $mapping->status,
            'next_billed_at' => $nextBilledAt,
            'current_period_starts_at' => $periodStartsAt,
            'current_period_ends_at' => $periodEndsAt,
            'scheduled_change' => is_array($data['scheduled_change'] ?? null) ? $data['scheduled_change'] : null,
            'custom_data' => is_array($data['custom_data'] ?? null) ? $data['custom_data'] : $mapping->custom_data,
            'last_event_at' => $occurredAt ?: now(),
        ]);

        $this->applySubscriptionStatus($subscription, $data, $status, $periodStartsAt, $periodEndsAt, $nextBilledAt, $occurredAt);
    }

    private function applySubscriptionStatus(
        TenantSubscription $subscription,
        array $data,
        string $status,
        ?Carbon $periodStartsAt,
        ?Carbon $periodEndsAt,
        ?Carbon $nextBilledAt,
        ?Carbon $occurredAt
    ): void {
        $startedAt = $this->parseDate($data['started_at'] ?? null) ?: $periodStartsAt ?: $subscription->starts_at ?: now();

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
            ]);
            $this->expireOtherLiveSubscriptions($subscription);
            return;
        }

        if (in_array($status, ['past_due', 'paused'], true)) {
            $subscription->update([
                'status' => TenantSubscription::STATUS_SUSPENDED,
                'ends_at' => $periodEndsAt ?: $subscription->ends_at,
            ]);
            return;
        }

        if ($status === 'canceled') {
            $cancelledAt = $this->parseDate($data['canceled_at'] ?? null) ?: $occurredAt ?: now();
            $subscription->update([
                'status' => TenantSubscription::STATUS_CANCELLED,
                'ends_at' => $periodEndsAt ?: $cancelledAt,
                'cancelled_at' => $cancelledAt,
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

        $subscription = $this->resolveTransactionSubscription($data, $references);
        if (! $subscription) {
            throw new RuntimeException("Paddle transaction {$transactionId} cannot be linked to PRODEX.");
        }

        $gatewayAmount = $this->transactionTotal($data);
        if ($gatewayAmount <= 0) {
            // Expected for a zero-cost trial checkout. Access is provisioned from
            // subscription.created/trialing; no fake "paid" invoice is recorded.
            Log::info("Paddle transaction {$transactionId} completed with zero total; payment record skipped.");
            return;
        }

        $payment = $this->findOrCreateTransactionPayment($eventId, $data, $subscription, $gatewayAmount);
        $lifecycle->markProviderPaid($payment, [
            'gateway_payment_id' => $transactionId,
            'transaction_id' => $transactionId,
        ]);

        // transaction.completed is sufficient evidence that money was captured.
        // If subscription lifecycle webhooks arrive later/out of order, grant
        // access now and let the subscription event overwrite dates with Paddle's
        // exact billing period when it arrives.
        if (! $subscription->isActive()) {
            $period = is_array($data['billing_period'] ?? null) ? $data['billing_period'] : [];
            $startsAt = $this->parseDate($period['starts_at'] ?? null) ?: now();
            $endsAt = $this->parseDate($period['ends_at'] ?? null) ?: $this->fallbackPeriodEnd($subscription, $startsAt);

            $subscription->update([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'trial_ends_at' => null,
                'ends_at' => $endsAt,
                'cancelled_at' => null,
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
        if ($transactionId === '') {
            return;
        }

        $subscription = $this->resolveTransactionSubscription($data, $references);
        if (! $subscription) {
            Log::warning("Paddle failed transaction {$transactionId} could not be linked to PRODEX.");
            return;
        }

        $gatewayAmount = $this->transactionTotal($data);
        $payment = $this->findOrCreateTransactionPayment($eventId, $data, $subscription, $gatewayAmount);
        $lifecycle->markFailed($payment);
    }

    private function handleAdjustment(array $data, SubscriptionLifecycleService $lifecycle): void
    {
        $action = strtolower((string) ($data['action'] ?? ''));
        $status = strtolower((string) ($data['status'] ?? ''));
        $transactionId = trim((string) ($data['transaction_id'] ?? ''));

        if (! in_array($action, ['refund', 'chargeback'], true) || $status !== 'approved' || $transactionId === '') {
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
        if (($data['type'] ?? null) === 'full') {
            $lifecycle->markRefunded($payment, $adjustmentId);
            return;
        }

        $metadata = $payment->metadata ?? [];
        $metadata['paddle_adjustments'] = array_values(array_unique(array_filter(array_merge(
            (array) ($metadata['paddle_adjustments'] ?? []),
            [$adjustmentId]
        ))));

        $payment->update([
            'metadata' => $metadata,
            'notes' => trim(($payment->notes ? $payment->notes."\n" : '').'Partial Paddle refund/chargeback: '.($adjustmentId ?: 'unknown')),
        ]);
    }

    private function resolveTransactionSubscription(array $data, PaddleCheckoutReference $references): ?TenantSubscription
    {
        $paddleSubscriptionId = trim((string) ($data['subscription_id'] ?? ''));
        if ($paddleSubscriptionId !== '') {
            $mapping = PaddleSubscription::where('paddle_subscription_id', $paddleSubscriptionId)->first();
            if ($mapping?->subscription) {
                return $mapping->subscription;
            }
        }

        $claims = $this->claimsFromData($data, $references);
        $subscription = $this->subscriptionFromClaims($claims);
        if (! $subscription) {
            return null;
        }

        if ($paddleSubscriptionId !== '') {
            $existingForLocal = PaddleSubscription::where('tenant_subscription_id', $subscription->id)->first();
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
                    'status' => 'unknown',
                    'custom_data' => is_array($data['custom_data'] ?? null) ? $data['custom_data'] : null,
                ]);
            }
        }

        return $subscription;
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

    private function claimsFromData(array $data, PaddleCheckoutReference $references): ?array
    {
        $customData = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];

        return $references->parse($customData['prodex_ref'] ?? null);
    }

    private function subscriptionFromClaims(?array $claims): ?TenantSubscription
    {
        if (! $claims) {
            return null;
        }

        $subscription = TenantSubscription::find($claims['subscription_id']);

        return $subscription && $this->claimsMatchSubscription($claims, $subscription)
            ? $subscription
            : null;
    }

    private function claimsMatchSubscription(array $claims, TenantSubscription $subscription): bool
    {
        return (string) $subscription->tenant_id === (string) $claims['tenant_id']
            && (int) $subscription->id === (int) $claims['subscription_id']
            && (int) $subscription->plan_id === (int) $claims['plan_id']
            && (string) $subscription->billing_cycle === (string) $claims['billing_cycle'];
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
