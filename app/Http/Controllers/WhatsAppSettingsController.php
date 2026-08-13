<?php

namespace App\Http\Controllers;

use App\Models\Central\GeneralSetting;
use App\Models\WhatsappSetting;
use App\Services\TenantLimitsService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppSettingsController extends Controller
{
    /**
     * Return the tenant's WhatsApp configuration, connection status,
     * automation toggles and remaining monthly quota.
     */
    public function show(Request $request, TenantLimitsService $limits)
    {
        $this->authorizeForUser($request->user('api'), 'view', WhatsappSetting::class);

        $setting = WhatsappSetting::instance();

        return response()->json([
            'globally_enabled' => GeneralSetting::whatsappEnabled(),
            'settings' => [
                'provider'             => $setting->provider,
                'phone_number_id'      => $setting->phone_number_id,
                'business_account_id'  => $setting->business_account_id,
                'webhook_verify_token' => $setting->webhook_verify_token,
                'default_country_code' => $setting->default_country_code,
                'is_connected'         => $setting->is_connected,
                'connected_at'         => $setting->connected_at,
                'has_access_token'     => ! empty($setting->access_token),
                'has_app_secret'       => ! empty($setting->app_secret),
                'automations'          => $this->normalizedAutomations($setting),
            ],
            'automation_keys' => WhatsappSetting::AUTOMATIONS,
            'webhook_url'     => url('/whatsapp/webhook/' . optional(tenant())->id),
            'quota' => [
                'used'      => $limits->getWhatsappUsage(),
                'max'       => $limits->getMaxAllowed('max_whatsapp_messages'),
                'remaining' => $limits->getWhatsappRemaining(),
            ],
        ]);
    }

    /**
     * Save credentials and automation toggles. Blank secret fields are left
     * unchanged so the masked values from the SPA don't wipe stored tokens.
     */
    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', WhatsappSetting::class);

        $validated = $request->validate([
            'phone_number_id'      => ['nullable', 'string', 'max:191'],
            'business_account_id'  => ['nullable', 'string', 'max:191'],
            'access_token'         => ['nullable', 'string', 'max:2000'],
            'app_secret'           => ['nullable', 'string', 'max:512'],
            'webhook_verify_token' => ['nullable', 'string', 'max:191'],
            'default_country_code' => ['nullable', 'string', 'max:8'],
            'automations'          => ['nullable', 'array'],
        ]);

        $setting = WhatsappSetting::instance();

        $setting->phone_number_id      = $validated['phone_number_id'] ?? null;
        $setting->business_account_id  = $validated['business_account_id'] ?? null;
        $setting->webhook_verify_token = $validated['webhook_verify_token'] ?? null;
        $setting->default_country_code = $validated['default_country_code'] ?? null;

        // Only overwrite secrets when a non-empty, non-masked value is provided.
        if (! empty($validated['access_token']) && $validated['access_token'] !== '••••••••') {
            $setting->access_token = $validated['access_token'];
        }
        if (! empty($validated['app_secret']) && $validated['app_secret'] !== '••••••••') {
            $setting->app_secret = $validated['app_secret'];
        }

        // Sanitize automation toggles to known keys only.
        $automations = [];
        foreach (WhatsappSetting::AUTOMATIONS as $key) {
            $automations[$key] = (bool) ($validated['automations'][$key] ?? false);
        }
        $setting->automations = $automations;

        $setting->save();

        return response()->json(['status' => 'success', 'message' => 'WhatsApp settings saved.']);
    }

    /**
     * Verify the stored credentials against the provider.
     */
    public function test(Request $request, WhatsAppService $service)
    {
        $this->authorizeForUser($request->user('api'), 'update', WhatsappSetting::class);

        if (! GeneralSetting::whatsappEnabled()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'WhatsApp is disabled by the platform administrator.',
            ], 422);
        }

        $result = $service->testConnection();

        return response()->json([
            'status'  => $result['success'] ? 'success' : 'error',
            'message' => $result['success']
                ? 'Connection successful. Your WhatsApp account is connected.'
                : ($result['error'] ?: 'Connection failed.'),
            'is_connected' => $result['success'],
        ], $result['success'] ? 200 : 422);
    }

    protected function normalizedAutomations(WhatsappSetting $setting): array
    {
        $stored = $setting->automations ?? [];
        $out = [];
        foreach (WhatsappSetting::AUTOMATIONS as $key) {
            $out[$key] = (bool) ($stored[$key] ?? false);
        }
        return $out;
    }
}
