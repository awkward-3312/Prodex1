<?php

namespace App\Services\Shopify;

use App\Models\ShopifyStore;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin Shopify Admin REST API client.
 *
 * - Auth via X-Shopify-Access-Token (custom app Admin API token).
 * - Handles the leaky-bucket rate limit: retries on 429 honoring Retry-After,
 *   and slows down pre-emptively when the bucket is close to full.
 * - Exposes cursor pagination via the Link header (page_info).
 */
class Client
{
    private string $domain;
    private string $token;
    private string $version;
    private HttpClient $http;

    public function __construct(string $shopDomain, string $accessToken, string $apiVersion = '2025-01')
    {
        $domain = preg_replace('#^https?://#i', '', trim($shopDomain));
        $this->domain = rtrim(explode('/', $domain)[0] ?? '', '/');
        $this->token = trim($accessToken);
        $this->version = trim($apiVersion) !== '' ? trim($apiVersion) : '2025-01';

        $this->http = new HttpClient([
            'base_uri' => "https://{$this->domain}/admin/api/{$this->version}/",
            'http_errors' => false,
            'timeout' => (int) env('SHOPIFY_HTTP_TIMEOUT', 30),
            'connect_timeout' => (int) env('SHOPIFY_HTTP_CONNECT_TIMEOUT', 10),
        ]);
    }

    public static function forStore(ShopifyStore $store): self
    {
        return new self(
            (string) $store->shop_domain,
            (string) $store->access_token,
            (string) ($store->api_version ?: '2025-01')
        );
    }

    /**
     * Stable identifier for the target store + credentials (cache keying).
     */
    public function fingerprint(): string
    {
        return md5($this->domain.'|'.$this->token);
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->request('GET', $endpoint, $query, null);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->request('POST', $endpoint, [], $data);
    }

    public function put(string $endpoint, array $data = []): Response
    {
        return $this->request('PUT', $endpoint, [], $data);
    }

    public function delete(string $endpoint, array $query = []): Response
    {
        return $this->request('DELETE', $endpoint, $query, null);
    }

    /**
     * Extract the page_info cursor for the next page from the Link header, if any.
     */
    public static function nextPageInfo(Response $response): ?string
    {
        $link = $response->header('link');
        if (! $link) {
            return null;
        }

        // Link: <https://...page_info=xyz>; rel="previous", <https://...page_info=abc>; rel="next"
        foreach (explode(',', $link) as $part) {
            if (stripos($part, 'rel="next"') === false) {
                continue;
            }
            if (preg_match('/page_info=([^&>]+)/', $part, $m)) {
                return urldecode($m[1]);
            }
        }

        return null;
    }

    private function request(string $method, string $endpoint, array $query, ?array $data): Response
    {
        $endpoint = ltrim($endpoint, '/');
        $maxRetries = max(0, min(10, (int) env('SHOPIFY_HTTP_RETRIES', 4)));

        $options = [
            'headers' => [
                'X-Shopify-Access-Token' => $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];
        if (! empty($query)) {
            $options['query'] = $query;
        }
        if ($data !== null) {
            $options['json'] = $data;
        }

        $attempt = 0;
        while (true) {
            $attempt++;

            try {
                $raw = $this->http->request($method, $endpoint, $options);
                $response = Response::fromPsr($raw);
            } catch (GuzzleException $e) {
                if ($attempt <= $maxRetries) {
                    usleep((int) (250000 * $attempt) + random_int(0, 100000));
                    continue;
                }

                return Response::fromTransportError($e->getMessage());
            }

            // Leaky bucket nearly full -> breathe before the next call
            $this->throttleFromCallLimit($response);

            if ($response->status() === 429 && $attempt <= $maxRetries) {
                $retryAfter = (float) ($response->header('retry-after') ?? 2.0);
                usleep((int) (max(0.5, $retryAfter) * 1000000));
                continue;
            }

            $retryableServer = in_array($response->status(), [500, 502, 503, 504], true)
                && strtoupper($method) !== 'POST';
            if ($retryableServer && $attempt <= $maxRetries) {
                usleep((int) (400000 * $attempt) + random_int(0, 150000));
                continue;
            }

            return $response;
        }
    }

    private function throttleFromCallLimit(Response $response): void
    {
        $limit = $response->header('x-shopify-shop-api-call-limit');
        if (! $limit || ! str_contains($limit, '/')) {
            return;
        }
        [$used, $max] = array_map('intval', explode('/', $limit, 2));
        if ($max > 0 && $used / $max >= 0.8) {
            // bucket drains at 2 req/s on standard plans
            usleep(500000);
        }
    }
}
