<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Cloud API (Meta / graph.facebook.com) driver.
 *
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class MetaCloudDriver implements WhatsAppDriverInterface
{
    protected const GRAPH_VERSION = 'v21.0';

    protected ?WhatsappSetting $settings = null;

    public function withSettings(WhatsappSetting $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    public function sendText(string $to, string $body): array
    {
        $endpoint = $this->baseUrl() . '/messages';

        try {
            $response = Http::withToken($this->settings->access_token)
                ->acceptJson()
                ->timeout(20)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => $this->normalizeNumber($to),
                    'type'              => 'text',
                    'text'              => [
                        'preview_url' => false,
                        'body'        => $body,
                    ],
                ]);

            if ($response->successful()) {
                $messageId = data_get($response->json(), 'messages.0.id');

                return ['success' => true, 'message_id' => $messageId, 'error' => null];
            }

            return [
                'success'    => false,
                'message_id' => null,
                'error'      => $this->extractError($response->json(), $response->status()),
            ];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp MetaCloudDriver sendText failed: ' . $e->getMessage());

            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function verifyConnection(): array
    {
        $endpoint = "https://graph.facebook.com/" . self::GRAPH_VERSION . "/{$this->settings->phone_number_id}";

        try {
            $response = Http::withToken($this->settings->access_token)
                ->acceptJson()
                ->timeout(20)
                ->get($endpoint, ['fields' => 'verified_name,display_phone_number,quality_rating']);

            if ($response->successful()) {
                return ['success' => true, 'error' => null, 'data' => $response->json()];
            }

            return [
                'success' => false,
                'error'   => $this->extractError($response->json(), $response->status()),
            ];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp MetaCloudDriver verifyConnection failed: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function baseUrl(): string
    {
        return "https://graph.facebook.com/" . self::GRAPH_VERSION . "/{$this->settings->phone_number_id}";
    }

    /**
     * WhatsApp expects E.164 digits without the leading "+" or spaces.
     */
    protected function normalizeNumber(string $to): string
    {
        $digits = preg_replace('/[^0-9]/', '', $to);

        return $digits ?? '';
    }

    /**
     * Pull a human-readable error message out of a Graph API error body.
     */
    protected function extractError(?array $body, int $status): string
    {
        $message = data_get($body, 'error.message');

        if ($message) {
            $code = data_get($body, 'error.code');

            return $code ? "[{$code}] {$message}" : $message;
        }

        return "WhatsApp API request failed (HTTP {$status}).";
    }
}
