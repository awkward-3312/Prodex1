<?php

namespace App\Services\PaymentGateways;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HasGatewaySettings
{
    protected ?array $resolvedCredentials = null;
    protected ?bool  $active = null;

    abstract public function getKey(): string;

    abstract protected function getRequiredCredentialKeys(): array;

    protected function loadSettings(): void
    {
        if ($this->resolvedCredentials !== null) {
            return;
        }

        $this->active = false;
        $dbCreds = [];

        try {
            $row = DB::connection('central')
                ->table('payment_gateway_settings')
                ->where('gateway', $this->getKey())
                ->first();

            if ($row) {
                $this->active = (bool) $row->is_active;

                if (! empty($row->credentials)) {
                    $decoded = json_decode($row->credentials, true);
                    if (is_array($decoded)) {
                        $dbCreds = $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Could not read {$this->getKey()} settings from DB: {$e->getMessage()}");
        }

        $this->resolvedCredentials = $this->resolveCredentials($dbCreds);
    }

    protected function resolveCredentials(array $dbCreds): array
    {
        $resolved = [];

        // Required keys first
        foreach ($this->getRequiredCredentialKeys() as $key) {
            $resolved[$key] = $this->decryptIfNeeded($dbCreds[$key] ?? '');
        }

        // All other keys stored in DB (webhook_id, secret_hash, etc.)
        // are also loaded so they're available to gateway methods.
        foreach ($dbCreds as $key => $value) {
            if (! array_key_exists($key, $resolved)) {
                $resolved[$key] = $this->decryptIfNeeded((string) ($value ?? ''));
            }
        }

        return $resolved;
    }

    protected function isGatewayAvailable(): bool
    {
        $this->loadSettings();

        if (! $this->active) {
            return false;
        }

        foreach ($this->getRequiredCredentialKeys() as $key) {
            if (empty($this->resolvedCredentials[$key])) {
                return false;
            }
        }

        return true;
    }

    protected function decryptIfNeeded(string $value): string
    {
        $value = trim($value);

        if (empty($value)) {
            return '';
        }

        if (str_starts_with($value, 'eyJ')) {
            try {
                return trim(Crypt::decryptString($value));
            } catch (\Throwable $e) {
                Log::warning("Failed to decrypt {$this->getKey()} credential: {$e->getMessage()}");
                return '';
            }
        }

        return $value;
    }

    public function getCredential(string $key): string
    {
        $this->loadSettings();
        return $this->resolvedCredentials[$key] ?? '';
    }

    /**
     * Check if valid credentials exist (ignoring is_active).
     * Used by webhook processing where disabled gateways must still verify signatures.
     */
    public function hasValidCredentials(): bool
    {
        $this->loadSettings();

        foreach ($this->getRequiredCredentialKeys() as $key) {
            if (empty($this->resolvedCredentials[$key])) {
                return false;
            }
        }

        return true;
    }
}
