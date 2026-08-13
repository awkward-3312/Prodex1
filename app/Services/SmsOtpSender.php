<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SmsSetting;
use App\Models\sms_gateway;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Log;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use Twilio\Rest\Client as TwilioClient;

class SmsOtpSender
{
    public function send(string $toPhone, string $message): void
    {
        $settings = Setting::where('deleted_at', '=', null)->first();
        if (! $settings || ! $settings->sms_gateway) {
            throw new \RuntimeException('SMS gateway is not configured');
        }

        $gateway = sms_gateway::where('id', $settings->sms_gateway)->where('deleted_at', '=', null)->first();
        if (! $gateway) {
            throw new \RuntimeException('SMS gateway is not configured');
        }

        $this->sendVia($gateway->title, $toPhone, $message);
    }

    /**
     * Send a message through a specific gateway by its title
     * (twilio | infobip | termii | custom).
     *
     * When $credentials is null they are resolved from the current tenant's
     * sms_settings table, so each tenant uses its own isolated configuration.
     * Central (platform) callers pass self::envCredentials() explicitly since
     * they run outside any tenant context.
     */
    public function sendVia(string $gatewayTitle, string $toPhone, string $message, ?array $credentials = null): void
    {
        $credentials = $credentials ?? self::tenantCredentials();

        if ($gatewayTitle === 'twilio') {
            $sid = $credentials['twilio_sid'] ?? null;
            $token = $credentials['twilio_token'] ?? null;
            $from = $credentials['twilio_from'] ?? null;
            if (! $sid || ! $token || ! $from) {
                throw new \RuntimeException('Twilio credentials are missing');
            }
            $client = new TwilioClient($sid, $token);
            $client->messages->create($toPhone, ['from' => $from, 'body' => $message]);
            return;
        }

        if ($gatewayTitle === 'infobip') {
            $BASE_URL = $credentials['infobip_base_url'] ?? null;
            $API_KEY = $credentials['infobip_api_key'] ?? null;
            $SENDER = $credentials['infobip_sender_from'] ?? null;
            if (! $BASE_URL || ! $API_KEY || ! $SENDER) {
                throw new \RuntimeException('Infobip credentials are missing');
            }

            $configuration = (new Configuration)
                ->setHost($BASE_URL)
                ->setApiKeyPrefix('Authorization', 'App')
                ->setApiKey('Authorization', $API_KEY);

            $client = new HttpClient;
            $sendSmsApi = new SendSmsApi($client, $configuration);

            $destination = (new SmsDestination)->setTo($toPhone);
            $sms = (new SmsTextualMessage)
                ->setFrom($SENDER)
                ->setText($message)
                ->setDestinations([$destination]);

            $request = (new SmsAdvancedTextualRequest)->setMessages([$sms]);
            $sendSmsApi->sendSmsMessage($request);
            return;
        }

        if ($gatewayTitle === 'termii') {
            $url = 'https://api.ng.termii.com/api/sms/send';
            $payload = [
                'to' => $toPhone,
                'from' => $credentials['termii_sender'] ?? null,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => $credentials['termii_api_key'] ?? null,
            ];
            if (! $payload['from'] || ! $payload['api_key']) {
                throw new \RuntimeException('Termii credentials are missing');
            }

            $client = new HttpClient;
            try {
                $client->post($url, ['json' => $payload]);
            } catch (\Throwable $e) {
                Log::error('Termii SMS Error: '.$e->getMessage());
                throw new \RuntimeException('Failed to send SMS');
            }
            return;
        }

        if ($gatewayTitle === 'custom') {
            try {
                (new CustomSmsGateway)->send($toPhone, $message, $credentials);
            } catch (\Throwable $e) {
                Log::error('Custom SMS gateway error: '.$e->getMessage());
                throw new \RuntimeException('Failed to send SMS via custom gateway');
            }
            return;
        }

        throw new \RuntimeException('Unsupported SMS gateway: '.$gatewayTitle);
    }

    /**
     * Credentials stored in the current tenant's database.
     */
    public static function tenantCredentials(): array
    {
        $row = SmsSetting::current();

        return [
            'twilio_sid' => $row->twilio_sid,
            'twilio_token' => $row->twilio_token,
            'twilio_from' => $row->twilio_from,
            'termii_api_key' => $row->termii_api_key,
            'termii_secret' => $row->termii_secret,
            'termii_sender' => $row->termii_sender,
            'infobip_base_url' => $row->infobip_base_url,
            'infobip_api_key' => $row->infobip_api_key,
            'infobip_sender_from' => $row->infobip_sender_from,
            'custom_api_url' => $row->custom_api_url,
            'custom_method' => $row->custom_method,
            'custom_content_type' => $row->custom_content_type,
            'custom_sender' => $row->custom_sender,
            'custom_success_keyword' => $row->custom_success_keyword,
            'custom_headers' => $row->custom_headers ?: [],
            'custom_payload' => $row->custom_payload ?: [],
        ];
    }

    /**
     * Platform-wide credentials from .env, used only by central (super admin)
     * senders that run outside any tenant context.
     */
    public static function envCredentials(): array
    {
        return [
            'twilio_sid' => env('TWILIO_SID'),
            'twilio_token' => env('TWILIO_TOKEN'),
            'twilio_from' => env('TWILIO_FROM'),
            'termii_api_key' => env('TERMI_KEY'),
            'termii_secret' => env('TERMI_SECRET'),
            'termii_sender' => env('TERMI_SENDER'),
            'infobip_base_url' => env('base_url'),
            'infobip_api_key' => env('api_key'),
            'infobip_sender_from' => env('sender_from'),
            'custom_api_url' => env('CUSTOM_SMS_API_URL'),
            'custom_method' => env('CUSTOM_SMS_METHOD', 'POST'),
            'custom_content_type' => env('CUSTOM_SMS_CONTENT_TYPE', 'json'),
            'custom_sender' => env('CUSTOM_SMS_SENDER', ''),
            'custom_success_keyword' => env('CUSTOM_SMS_SUCCESS_KEYWORD'),
            'custom_headers' => self::decodeBase64Json(env('CUSTOM_SMS_HEADERS')),
            'custom_payload' => self::decodeBase64Json(env('CUSTOM_SMS_PAYLOAD')),
        ];
    }

    private static function decodeBase64Json($value): array
    {
        if (empty($value)) {
            return [];
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return [];
        }

        $parsed = json_decode($decoded, true);

        return is_array($parsed) ? $parsed : [];
    }
}
