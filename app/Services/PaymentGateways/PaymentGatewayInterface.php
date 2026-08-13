<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;

interface PaymentGatewayInterface
{
    /**
     * Create a checkout session from an existing TenantBillingPayment
     * (used for tenant-side plan changes / renewals).
     */
    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string;

    /**
     * Create a checkout session from raw parameters
     * (used for new signups before any tenant exists).
     *
     * @return array{url: string, session_id: string}
     */
    public function createCheckoutUrl(
        float $amount,
        string $currency,
        string $productName,
        string $description,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): array;

    /**
     * Verify a webhook payload and return normalized data.
     *
     * @return array{
     *     valid: bool,
     *     event_type: string,
     *     gateway_payment_id: string,
     *     transaction_id: string,
     *     payment_id: int|null,
     *     tenant_id: string|null,
     *     registration_id: int|null,
     *     status: string
     * }
     */
    public function verifyWebhook(string $payload, string $signature): array;

    /**
     * Verify a checkout session status directly with the gateway.
     *
     * @return array{status: string, gateway_payment_id: string|null, transaction_id: string|null}
     *   status: 'paid', 'pending', 'failed', or 'unknown'
     */
    public function verifyPaymentStatus(string $sessionId): array;

    public function getKey(): string;

    public function isAvailable(): bool;

    /**
     * @return array{key: string, label: string, icon: string, color: string}
     */
    public function getDisplayInfo(): array;
}
