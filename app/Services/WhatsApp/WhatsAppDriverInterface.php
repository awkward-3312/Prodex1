<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappSetting;

interface WhatsAppDriverInterface
{
    /**
     * Send a plain text message.
     *
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function sendText(string $to, string $body): array;

    /**
     * Verify the stored credentials can reach the provider.
     *
     * @return array{success: bool, error: ?string}
     */
    public function verifyConnection(): array;

    /**
     * Bind the tenant settings (credentials) the driver should use.
     */
    public function withSettings(WhatsappSetting $settings): static;
}
