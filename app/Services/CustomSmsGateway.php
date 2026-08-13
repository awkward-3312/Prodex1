<?php

namespace App\Services;

use GuzzleHttp\Client as HttpClient;

class CustomSmsGateway
{
    /**
     * When $credentials is null the configuration is loaded from the current
     * tenant's sms_settings table. Central callers pass
     * SmsOtpSender::envCredentials() explicitly.
     */
    public function send(string $toPhone, string $message, ?array $credentials = null): void
    {
        $credentials = $credentials ?? SmsOtpSender::tenantCredentials();

        $apiUrl = $credentials['custom_api_url'] ?? null;
        if (! $apiUrl) {
            throw new \RuntimeException('Custom SMS gateway is not configured');
        }

        $method = strtoupper($credentials['custom_method'] ?: 'POST');
        $contentType = strtolower($credentials['custom_content_type'] ?: 'json');
        $sender = $credentials['custom_sender'] ?? '';
        $successKeyword = $credentials['custom_success_keyword'] ?? null;

        $replacements = [
            '{phone}' => $toPhone,
            '{message}' => $message,
            '{sender}' => $sender,
        ];

        $headers = $this->interpolate(
            is_array($credentials['custom_headers'] ?? null) ? $credentials['custom_headers'] : [],
            $replacements
        );
        $payload = $this->interpolate(
            is_array($credentials['custom_payload'] ?? null) ? $credentials['custom_payload'] : [],
            $replacements
        );

        $finalUrl = strtr($apiUrl, $replacements);

        $options = [
            'headers' => $headers,
            'http_errors' => false,
        ];

        if ($method === 'GET') {
            $options['query'] = $payload;
        } elseif ($contentType === 'form') {
            $options['form_params'] = $payload;
        } else {
            $options['json'] = $payload;
        }

        $client = new HttpClient;
        $response = $client->request($method, $finalUrl, $options);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Custom SMS gateway returned HTTP '.$status.' '.$body);
        }

        if ($successKeyword && stripos($body, $successKeyword) === false) {
            throw new \RuntimeException('Custom SMS gateway reported a failure: '.$body);
        }
    }

    private function interpolate(array $config, array $replacements): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->interpolate($value, $replacements);
            } elseif (is_string($value)) {
                $result[$key] = strtr($value, $replacements);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
