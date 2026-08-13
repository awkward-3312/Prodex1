<?php

namespace App\Services;

use App\Models\Central\GeneralSetting;
use App\Models\WhatsappLog;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappUsage;
use App\Services\WhatsApp\MetaCloudDriver;
use App\Services\WhatsApp\WhatsAppDriverInterface;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-facing WhatsApp orchestrator. Resolves the configured provider
 * driver, enforces the per-plan monthly quota, sends the message and writes
 * a delivery log. All public methods are safe to call inside a tenant
 * context (tenancy already bootstrapped).
 */
class WhatsAppService
{
    public function __construct(protected TenantLimitsService $limits)
    {
    }

    /**
     * Global toggle (super admin) AND this tenant being connected.
     */
    public function isAvailable(): bool
    {
        try {
            if (! GeneralSetting::whatsappEnabled()) {
                return false;
            }

            $settings = WhatsappSetting::instance();

            return $settings->is_connected && $settings->hasCredentials();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Send a plain text WhatsApp message. Always records a log row.
     * Returns true only when the provider accepted the message.
     */
    public function send(string $to, string $body, array $opts = []): bool
    {
        $log = new WhatsappLog([
            'recipient'    => $to,
            'template_key' => $opts['template_key'] ?? null,
            'message_type' => $opts['message_type'] ?? 'text',
            'body'         => $body,
            'status'       => WhatsappLog::STATUS_PENDING,
        ]);

        // Global / connection gate.
        if (! $this->isAvailable()) {
            return $this->failLog($log, 'WhatsApp is not enabled or this workspace is not connected.');
        }

        // Per-plan monthly quota.
        if ($this->limits->whatsappQuotaReached()) {
            return $this->failLog($log, 'Monthly WhatsApp message quota reached for your plan.');
        }

        $settings = WhatsappSetting::instance();
        $driver = $this->resolveDriver($settings);

        if (! $driver) {
            return $this->failLog($log, "Unsupported WhatsApp provider: {$settings->provider}.");
        }

        $result = $driver->withSettings($settings)->sendText($to, $body);

        if ($result['success']) {
            $log->status = WhatsappLog::STATUS_SENT;
            $log->provider_message_id = $result['message_id'] ?? null;
            $log->sent_at = now();
            $log->save();

            try {
                WhatsappUsage::bump();
            } catch (\Throwable $e) {
                Log::warning('WhatsApp usage counter bump failed: ' . $e->getMessage());
            }

            return true;
        }

        return $this->failLog($log, $result['error'] ?? 'Unknown provider error.');
    }

    /**
     * Render a stored template and send it. No-op (returns false) if the
     * template is missing or inactive.
     */
    public function sendTemplate(string $key, string $to, array $variables = [], array $opts = []): bool
    {
        $template = WhatsappTemplate::where('key', $key)->where('is_active', true)->first();

        if (! $template) {
            return false;
        }

        $variables = $this->withDefaultVariables($variables);

        return $this->send(
            $to,
            $template->render($variables),
            array_merge(['template_key' => $key, 'message_type' => 'template'], $opts)
        );
    }

    /**
     * Verify credentials against the provider. Used by the "Test connection"
     * button. Persists the resulting connection state.
     *
     * @return array{success: bool, error: ?string}
     */
    public function testConnection(): array
    {
        $settings = WhatsappSetting::instance();

        if (! $settings->hasCredentials()) {
            return ['success' => false, 'error' => 'Missing phone number ID or access token.'];
        }

        $driver = $this->resolveDriver($settings);

        if (! $driver) {
            return ['success' => false, 'error' => "Unsupported WhatsApp provider: {$settings->provider}."];
        }

        $result = $driver->withSettings($settings)->verifyConnection();

        $settings->is_connected = (bool) $result['success'];
        $settings->connected_at = $result['success'] ? now() : null;
        $settings->save();

        return ['success' => (bool) $result['success'], 'error' => $result['error'] ?? null];
    }

    /**
     * Resolve the driver for the tenant's configured provider.
     * Only Meta Cloud API is implemented for now.
     */
    protected function resolveDriver(WhatsappSetting $settings): ?WhatsAppDriverInterface
    {
        return match ($settings->provider) {
            'meta_cloud', null, '' => new MetaCloudDriver(),
            default => null,
        };
    }

    /**
     * Merge in always-available variables (company name, date).
     */
    protected function withDefaultVariables(array $variables): array
    {
        $defaults = ['{{date}}' => now()->format('Y-m-d')];

        try {
            $company = \App\Models\Company::find(1);
            if ($company) {
                $defaults['{{company_name}}'] = $company->name;
            }
        } catch (\Throwable $e) {
            // Company table may not exist in some contexts; ignore.
        }

        return array_merge($defaults, $variables);
    }

    /**
     * Persist a failed log row and return false.
     */
    protected function failLog(WhatsappLog $log, string $error): bool
    {
        $log->status = WhatsappLog::STATUS_FAILED;
        $log->error = $error;
        $log->save();

        return false;
    }
}
