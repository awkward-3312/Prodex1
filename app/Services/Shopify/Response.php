<?php

namespace App\Services\Shopify;

use Psr\Http\Message\ResponseInterface;

/**
 * Normalized HTTP response wrapper (mirrors the WooCommerce client contract).
 */
class Response
{
    private int $status;
    private string $body;
    private array $headers;
    private ?string $error;

    private function __construct(int $status, string $body, array $headers, ?string $error = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
        $this->error = $error;
    }

    public static function fromPsr(ResponseInterface $raw): self
    {
        $headers = [];
        foreach ($raw->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }

        return new self($raw->getStatusCode(), (string) $raw->getBody(), $headers);
    }

    public static function fromTransportError(string $message): self
    {
        return new self(0, '', [], $message);
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function json()
    {
        return json_decode($this->body, true);
    }

    public function header(string $name): ?string
    {
        $key = strtolower(trim($name));
        if ($key === '' || empty($this->headers[$key])) {
            return null;
        }

        return (string) $this->headers[$key][0];
    }

    public function error(): ?string
    {
        if ($this->error !== null) {
            return $this->error;
        }
        if ($this->successful()) {
            return null;
        }

        $json = $this->json();
        if (is_array($json) && isset($json['errors'])) {
            return is_string($json['errors'])
                ? $json['errors']
                : json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
        }

        return 'HTTP '.$this->status;
    }
}
