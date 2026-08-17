<?php

namespace App\Http\Controllers;

use App\Models\PaymentSetting;
use App\Models\Setting;
use Illuminate\Http\Request;

class Payment_gateway_SettingsController extends Controller
{
    // -------------- Get Payment Gateway ---------------\\

    public function Get_payment_gateway(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'payment_gateway', Setting::class);

        $item['stripe_key'] = PaymentSetting::current()->stripe_key;
        $item['stripe_secret'] = '';
        $item['card_processing_mode'] = PaymentSetting::current()->effectiveCardProcessingMode();
        $item['deleted'] = false;

        return response()->json(['gateway' => $item], 200);
    }

    public function get_payment_gateway_ws(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        $item['stripe_key'] = PaymentSetting::current()->stripe_key;
        $item['stripe_secret'] = '';
        $item['card_processing_mode'] = PaymentSetting::current()->effectiveCardProcessingMode();
        $item['deleted'] = false;

        return response()->json(['gateway' => $item], 200);
    }

    // -------------- Update  Payment Gateway ---------------\\

    public function Update_payment_gateway(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'payment_gateway', Setting::class);

        $request->validate([
            'card_processing_mode' => 'nullable|in:external_terminal,stripe',
        ]);

        $payment_settings = PaymentSetting::current();
        $cardProcessingMode = $request->input('card_processing_mode') ?: PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL;

        if ($request['deleted'] == 'true') {
            $payment_settings->update([
                'stripe_key' => null,
                'stripe_secret' => null,
                'card_processing_mode' => $cardProcessingMode,
            ]);
        } else {
            $payment_settings->update([
                'stripe_key' => $request['stripe_key'] !== null ? $request['stripe_key'] : null,
                'stripe_secret' => $request['stripe_secret'] !== null ? $request['stripe_secret'] : $payment_settings->stripe_secret,
                'card_processing_mode' => $cardProcessingMode,
            ]);
        }

        return response()->json(['success' => true]);

    }
}
