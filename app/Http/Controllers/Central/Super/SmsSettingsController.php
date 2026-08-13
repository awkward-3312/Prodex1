<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\SmsSetting;
use App\Services\SmsOtpSender;
use Illuminate\Http\Request;

class SmsSettingsController extends Controller
{
    public function index()
    {
        $setting = SmsSetting::instance();

        return view('central.super.settings.sms', [
            'setting'  => $setting,
            'gateways' => SmsSetting::GATEWAYS,
        ]);
    }

    public function update(Request $request)
    {
        $gatewayKeys = array_filter(array_keys(SmsSetting::GATEWAYS));

        $validated = $request->validate([
            'sms_gateway'            => ['nullable', 'string', 'in:' . implode(',', $gatewayKeys)],

            'twilio_sid'             => ['nullable', 'string', 'max:255'],
            'twilio_token'           => ['nullable', 'string', 'max:255'],
            'twilio_from'            => ['nullable', 'string', 'max:50'],

            'termii_api_key'         => ['nullable', 'string', 'max:255'],
            'termii_secret'          => ['nullable', 'string', 'max:255'],
            'termii_sender'          => ['nullable', 'string', 'max:50'],

            'infobip_base_url'       => ['nullable', 'string', 'max:255'],
            'infobip_api_key'        => ['nullable', 'string', 'max:255'],
            'infobip_sender_from'    => ['nullable', 'string', 'max:50'],

            'custom_api_url'         => ['nullable', 'string', 'max:1000'],
            'custom_method'          => ['nullable', 'string', 'in:POST,GET,PUT'],
            'custom_content_type'    => ['nullable', 'string', 'in:json,form'],
            'custom_sender'          => ['nullable', 'string', 'max:100'],
            'custom_success_keyword' => ['nullable', 'string', 'max:255'],
            'custom_headers'         => ['nullable', 'string', 'max:5000'],
            'custom_payload'         => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['sms_gateway'] = $validated['sms_gateway'] ?? null;

        // Headers / payload are edited as JSON text — parse (and reject bad JSON).
        foreach (['custom_headers', 'custom_payload'] as $jsonField) {
            $raw = trim((string) ($validated[$jsonField] ?? ''));
            if ($raw === '') {
                $validated[$jsonField] = [];
                continue;
            }
            $parsed = json_decode($raw, true);
            if (! is_array($parsed)) {
                return back()->withInput()->withErrors([$jsonField => 'Invalid JSON — expected an object like {"key": "value"}.']);
            }
            $validated[$jsonField] = $parsed;
        }

        // Masked / empty secrets keep their stored value (handled by the model),
        // but drop them here so they don't overwrite anything by accident.
        foreach (SmsSetting::SECRET_FIELDS as $secret) {
            if (empty($validated[$secret]) || $validated[$secret] === SmsSetting::SECRET_MASK) {
                unset($validated[$secret]);
            }
        }

        SmsSetting::instance()->update($validated);

        // Keep the legacy gateway selector in General Settings in sync so the
        // subscription-reminder command keeps reading a consistent value.
        GeneralSetting::instance()->update(['sms_gateway' => $validated['sms_gateway']]);

        return back()->with('success', 'SMS settings saved successfully.');
    }

    public function sendTest(Request $request)
    {
        $request->validate([
            'test_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,}$/'],
        ]);

        $setting = SmsSetting::instance();

        if (! $setting->sms_gateway) {
            return back()->withErrors(['test_phone' => 'Select and save an SMS gateway first.']);
        }

        try {
            app(SmsOtpSender::class)->sendVia(
                $setting->sms_gateway,
                trim($request->test_phone),
                'This is a test SMS from ' . config('app.name', 'Stocky') . '. Your SMS gateway is configured correctly.',
                $setting->credentials()
            );

            return back()->with('success', "Test SMS sent to {$request->test_phone}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['test_phone' => 'Failed to send test SMS: ' . $e->getMessage()]);
        }
    }
}
