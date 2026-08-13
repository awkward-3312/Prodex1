<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SmsSetting;
use App\Models\sms_gateway;
use Illuminate\Http\Request;

class Sms_SettingsController extends Controller
{
    // -------------- Get_sms_config ---------------\\

    public function get_sms_config(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'sms_settings', Setting::class);

        $sms_settings = SmsSetting::current();

        $twilio['TWILIO_SID'] = $sms_settings->twilio_sid;
        $twilio['TWILIO_FROM'] = $sms_settings->twilio_from;
        $twilio['TWILIO_TOKEN'] = '';

        $termi['TERMI_KEY'] = $sms_settings->termii_api_key;
        $termi['TERMI_SECRET'] = $sms_settings->termii_secret;
        $termi['TERMI_SENDER'] = $sms_settings->termii_sender;

        $infobip['base_url'] = $sms_settings->infobip_base_url;
        $infobip['api_key'] = $sms_settings->infobip_api_key;
        $infobip['sender_from'] = $sms_settings->infobip_sender_from;

        $custom = [
            'api_url' => $sms_settings->custom_api_url,
            'method' => $sms_settings->custom_method ?: 'POST',
            'content_type' => $sms_settings->custom_content_type ?: 'json',
            'sender' => $sms_settings->custom_sender,
            'success_keyword' => $sms_settings->custom_success_keyword,
            'headers' => $sms_settings->custom_headers ?: new \stdClass,
            'payload' => $sms_settings->custom_payload ?: new \stdClass,
        ];

        $sms_gateway = sms_gateway::where('deleted_at', '=', null)->get(['id', 'title']);
        $settings = Setting::where('deleted_at', '=', null)->first();

        if ($settings->sms_gateway) {
            if (sms_gateway::where('id', $settings->sms_gateway)->where('deleted_at', '=', null)->first()) {
                $default_sms_gateway = $settings->sms_gateway;
            } else {
                $default_sms_gateway = '';
            }
        } else {
            $default_sms_gateway = '';
        }

        return response()->json([
            'twilio' => $twilio,
            'termi' => $termi,
            'infobip' => $infobip,
            'custom' => $custom,
            'sms_gateway' => $sms_gateway,
            'default_sms_gateway' => $default_sms_gateway,
        ], 200);
    }

    // -------------- update_twilio_config ---------------\\

    public function update_twilio_config(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'sms_settings', Setting::class);

        $sms_settings = SmsSetting::current();

        $sms_settings->update([
            'twilio_sid' => $request['TWILIO_SID'] !== null ? $request['TWILIO_SID'] : $sms_settings->twilio_sid,
            'twilio_token' => $request['TWILIO_TOKEN'] !== null ? $request['TWILIO_TOKEN'] : $sms_settings->twilio_token,
            'twilio_from' => $request['TWILIO_FROM'] !== null ? $request['TWILIO_FROM'] : $sms_settings->twilio_from,
        ]);

        return response()->json(['success' => true]);

    }

    // -------------- Update update_termi_config ---------------\\

    public function update_termi_config(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'sms_settings', Setting::class);

        $sms_settings = SmsSetting::current();

        $sms_settings->update([
            'termii_api_key' => $request['TERMI_KEY'] !== null ? $request['TERMI_KEY'] : $sms_settings->termii_api_key,
            'termii_secret' => $request['TERMI_SECRET'] !== null ? $request['TERMI_SECRET'] : $sms_settings->termii_secret,
            'termii_sender' => $request['TERMI_SENDER'] !== null ? $request['TERMI_SENDER'] : $sms_settings->termii_sender,
        ]);

        return response()->json(['success' => true]);

    }

    // -------------- update_infobip_config ---------------\\

    public function update_infobip_config(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'sms_settings', Setting::class);

        $sms_settings = SmsSetting::current();

        $sms_settings->update([
            'infobip_base_url' => $request['base_url'] !== null ? $request['base_url'] : $sms_settings->infobip_base_url,
            'infobip_api_key' => $request['api_key'] !== null ? $request['api_key'] : $sms_settings->infobip_api_key,
            'infobip_sender_from' => $request['sender_from'] !== null ? $request['sender_from'] : $sms_settings->infobip_sender_from,
        ]);

        return response()->json(['success' => true]);

    }

    // -------------- update_custom_config ---------------\\

    public function update_custom_config(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'sms_settings', Setting::class);

        $request->validate([
            'api_url' => 'required|url',
            'method' => 'required|in:GET,POST,PUT',
            'content_type' => 'required|in:json,form',
            'headers' => 'nullable|array',
            'payload' => 'nullable|array',
            'sender' => 'nullable|string|max:64',
            'success_keyword' => 'nullable|string|max:128',
        ]);

        SmsSetting::current()->update([
            'custom_api_url' => $request->input('api_url'),
            'custom_method' => $request->input('method'),
            'custom_content_type' => $request->input('content_type'),
            'custom_sender' => $request->input('sender'),
            'custom_success_keyword' => $request->input('success_keyword'),
            'custom_headers' => $request->input('headers', []),
            'custom_payload' => $request->input('payload', []),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- update_Default_SMS ---------------\\

    public function update_Default_SMS(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'sms_settings', Setting::class);

        if ($request['default_sms_gateway'] != 'null') {
            $sms_gateway = $request['default_sms_gateway'];
        } else {
            $sms_gateway = null;
        }

        Setting::whereId(1)->update([
            'sms_gateway' => $sms_gateway,
        ]);

    }
}
