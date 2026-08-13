<?php

namespace App\Services\Zatca;

use App\Models\ZatcaLog;
use App\Models\ZatcaSetting;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the ZATCA Fatoora gateway (Developer Portal / Simulation /
 * Core), implementing the official E-Invoicing API spec:
 *
 *   POST /compliance                  — issue Compliance CSID (CSR + OTP)
 *   POST /compliance/invoices         — compliance checks of sample documents
 *   POST /production/csids            — issue Production CSID
 *   POST /production/csids/renewal    — renew Production CSID
 *   POST /invoices/reporting/single   — report simplified invoices
 *   POST /invoices/clearance/single   — clear standard invoices
 */
class ZatcaApiClient
{
    public function __construct(protected ZatcaSetting $settings)
    {
    }

    /**
     * Issue a Compliance CSID from the CSR. Requires the OTP obtained from
     * the Fatoora portal (any 6 digits on sandbox).
     *
     * @return array{requestID: mixed, binarySecurityToken: string, secret: string}
     */
    public function requestComplianceCsid(string $csrPem, string $otp): array
    {
        $response = $this->request('compliance_csid', 'POST', '/compliance', [
            'headers' => ['OTP' => trim($otp)],
            'json' => ['csr' => base64_encode($csrPem)],
        ]);

        return $this->expect($response, ['binarySecurityToken', 'secret'], 'Compliance CSID request failed');
    }

    /**
     * Submit a signed sample document for a compliance check.
     */
    public function complianceCheck(string $invoiceHash, string $uuid, string $signedXml): array
    {
        $response = $this->request('compliance_check', 'POST', '/compliance/invoices', [
            'auth' => [$this->settings->ccsid_token, $this->settings->ccsid_secret],
            'json' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ]);

        return ['status' => $response->status(), 'body' => $this->json($response)];
    }

    /**
     * Exchange the Compliance CSID for a Production CSID.
     *
     * @return array{requestID: mixed, binarySecurityToken: string, secret: string}
     */
    public function requestProductionCsid(string $complianceRequestId): array
    {
        $response = $this->request('production_csid', 'POST', '/production/csids', [
            'auth' => [$this->settings->ccsid_token, $this->settings->ccsid_secret],
            'json' => ['compliance_request_id' => (string) $complianceRequestId],
        ]);

        return $this->expect($response, ['binarySecurityToken', 'secret'], 'Production CSID request failed');
    }

    /**
     * Renew the Production CSID with a fresh CSR + OTP.
     */
    public function renewProductionCsid(string $csrPem, string $otp): array
    {
        $response = $this->request('production_csid_renewal', 'PATCH', '/production/csids/renewal', [
            'headers' => ['OTP' => trim($otp)],
            'auth' => [$this->settings->pcsid_token, $this->settings->pcsid_secret],
            'json' => ['csr' => base64_encode($csrPem)],
        ]);

        return $this->expect($response, ['binarySecurityToken', 'secret'], 'Production CSID renewal failed');
    }

    /**
     * Report a signed simplified invoice (B2C). 200 = REPORTED,
     * 202 = accepted with warnings.
     */
    public function reportInvoice(string $invoiceHash, string $uuid, string $signedXml, ?int $documentId = null): array
    {
        $response = $this->request('reporting', 'POST', '/invoices/reporting/single', [
            'auth' => [$this->settings->pcsid_token, $this->settings->pcsid_secret],
            'headers' => ['Clearance-Status' => '0'],
            'json' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ], $documentId);

        return ['status' => $response->status(), 'body' => $this->json($response)];
    }

    /**
     * Clear a standard invoice (B2B). ZATCA validates, stamps and returns
     * the cleared XML.
     */
    public function clearInvoice(string $invoiceHash, string $uuid, string $signedXml, ?int $documentId = null): array
    {
        $response = $this->request('clearance', 'POST', '/invoices/clearance/single', [
            'auth' => [$this->settings->pcsid_token, $this->settings->pcsid_secret],
            'headers' => ['Clearance-Status' => '1'],
            'json' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ], $documentId);

        return ['status' => $response->status(), 'body' => $this->json($response)];
    }

    // ------------------------------------------------------------------

    protected function request(string $action, string $method, string $path, array $options, ?int $documentId = null)
    {
        $url = rtrim($this->settings->baseUrl(), '/').$path;

        $pending = Http::timeout((int) config('zatca.timeout', 30))
            ->acceptJson()
            ->withHeaders(array_merge([
                'Accept-Version' => 'V2',
                'Accept-Language' => 'en',
            ], $options['headers'] ?? []));

        if (! empty($options['auth'])) {
            $pending = $pending->withBasicAuth($options['auth'][0] ?? '', $options['auth'][1] ?? '');
        }

        $response = $pending->send($method, $url, ['json' => $options['json'] ?? []]);

        try {
            ZatcaLog::create([
                'zatca_document_id' => $documentId,
                'action' => $action,
                'endpoint' => $path,
                'http_status' => $response->status(),
                'request_meta' => ['environment' => $this->settings->environment],
                'response_body' => mb_substr($this->redactSecrets((string) $response->body()), 0, 60000),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the flow (table may not exist yet).
        }

        return $response;
    }

    /**
     * CSID-issuing responses carry the credentials used to authenticate all
     * future submissions; they are stored encrypted on zatca_settings and
     * must never land in the plaintext log table (which the API exposes).
     */
    protected function redactSecrets(string $body): string
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $body;
        }

        foreach (['binarySecurityToken', 'secret', 'privateKey'] as $key) {
            if (array_key_exists($key, $decoded)) {
                $decoded[$key] = '[REDACTED]';
            }
        }

        return json_encode($decoded);
    }

    protected function expect($response, array $keys, string $errorPrefix): array
    {
        $body = $this->json($response);

        if (! $response->successful()) {
            $detail = $body['message'] ?? ($body['errors'] ?? null) ?? $response->body();
            throw new \RuntimeException($errorPrefix.' (HTTP '.$response->status().'): '.(is_string($detail) ? $detail : json_encode($detail)));
        }

        foreach ($keys as $key) {
            if (empty($body[$key])) {
                throw new \RuntimeException($errorPrefix.": missing '{$key}' in response.");
            }
        }

        return $body;
    }

    protected function json($response): array
    {
        $decoded = json_decode((string) $response->body(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
