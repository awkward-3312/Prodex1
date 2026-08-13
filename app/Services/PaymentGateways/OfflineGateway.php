<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\DB;

class OfflineGateway implements PaymentGatewayInterface
{
    use HasGatewaySettings;

    public function getKey(): string
    {
        return 'offline';
    }

    protected function getRequiredCredentialKeys(): array
    {
        return []; // No API keys needed
    }

    public function isAvailable(): bool
    {
        return $this->isGatewayAvailable();
    }

    /**
     * Override: offline gateway only needs is_active (no credential check).
     */
    protected function isGatewayAvailable(): bool
    {
        $this->loadSettings();
        return (bool) $this->active;
    }

    public function getDisplayInfo(): array
    {
        return [
            'key'         => 'offline',
            'label'       => 'Bank Transfer',
            'description' => 'Pay via bank transfer and upload proof of payment.',
            'icon'        => 'bi-bank',
            'color'       => '#0d9488',
        ];
    }

    /**
     * Offline payments don't create external checkout sessions.
     * The payment record is created locally and stays pending until admin approves.
     */
    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string {
        $payment->update([
            'gateway_payment_id' => 'offline_' . $payment->id,
        ]);

        return $successUrl;
    }

    /**
     * Offline payments don't create external checkout URLs.
     * Returns a self-referencing result so the checkout flow can handle it internally.
     */
    public function createCheckoutUrl(
        float $amount,
        string $currency,
        string $productName,
        string $description,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): array {
        return [
            'url'        => $successUrl,
            'session_id' => 'offline_' . uniqid(),
        ];
    }

    /**
     * Offline payments are verified manually by admin — no webhook verification.
     */
    public function verifyWebhook(string $payload, string $signature): array
    {
        return [
            'valid'              => false,
            'event_type'         => '',
            'gateway_payment_id' => '',
            'transaction_id'     => '',
            'payment_id'         => null,
            'tenant_id'          => null,
            'registration_id'    => null,
            'status'             => 'unknown',
        ];
    }

    /**
     * Offline payments stay pending until admin manually approves.
     */
    public function verifyPaymentStatus(string $sessionId): array
    {
        return [
            'status'             => 'pending',
            'gateway_payment_id' => $sessionId,
            'transaction_id'     => null,
        ];
    }
}
