<?php

namespace App\Services\Zatca;

use App\Models\Setting;
use App\Models\ZatcaDocument;
use App\Models\ZatcaLog;
use App\Models\ZatcaSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates the ZATCA Phase 2 lifecycle:
 *
 *  Onboarding: EC key + CSR -> Compliance CSID (OTP) -> compliance checks of
 *  every declared document type -> Production CSID.
 *
 *  Operation: for each Sale / SaleReturn — allocate ICV + PIH atomically,
 *  build UBL XML, sign (XAdES + QR), then report (simplified) or clear
 *  (standard) through the Fatoora gateway.
 */
class ZatcaService
{
    public function __construct(
        protected CertificateBuilder $certificateBuilder,
        protected UblInvoiceXmlGenerator $xmlGenerator,
        protected InvoiceSigner $signer,
        protected SaleInvoiceMapper $mapper
    ) {
    }

    // ------------------------------------------------------------------
    // Onboarding
    // ------------------------------------------------------------------

    /**
     * Generate (or regenerate) the EGS key pair and CSR.
     */
    public function generateCsr(ZatcaSetting $zatca): ZatcaSetting
    {
        if (empty($zatca->device_serial)) {
            $zatca->device_serial = (string) Str::uuid();
        }

        $material = $this->certificateBuilder->build($zatca);

        // A new key pair invalidates every previously issued CSID: certificates
        // bind to the old public key, so signing would produce mismatched
        // signatures. Force a full re-onboarding.
        $zatca->fill([
            'private_key' => $material['private_key'],
            'csr' => $material['csr'],
            'onboarding_status' => 'not_started',
            'compliance_request_id' => null,
            'ccsid_token' => null,
            'ccsid_secret' => null,
            'pcsid_token' => null,
            'pcsid_secret' => null,
        ])->save();

        return $zatca;
    }

    /**
     * Full onboarding: CSR -> CCSID -> compliance checks -> PCSID.
     *
     * @return array{status: string, compliance_checks: array}
     */
    public function onboard(ZatcaSetting $zatca, string $otp): array
    {
        if (empty($zatca->csr) || empty($zatca->private_key)) {
            $this->generateCsr($zatca);
        }

        $client = new ZatcaApiClient($zatca);

        // 1. Compliance CSID
        $ccsid = $client->requestComplianceCsid($zatca->csr, $otp);
        $zatca->fill([
            'compliance_request_id' => (string) ($ccsid['requestID'] ?? ''),
            'ccsid_token' => $ccsid['binarySecurityToken'],
            'ccsid_secret' => $ccsid['secret'],
            'onboarding_status' => 'csid_issued',
        ])->save();

        // 2. Compliance checks for every functionality declared in the CSR
        $checks = $this->runComplianceChecks($zatca);
        $failed = collect($checks)->filter(fn ($c) => ! $c['passed'])->count();
        $zatca->fill([
            'compliance_results' => $checks,
            'onboarding_status' => $failed === 0 ? 'compliance_checked' : 'csid_issued',
        ])->save();

        if ($failed > 0) {
            return ['status' => 'compliance_failed', 'compliance_checks' => $checks];
        }

        // 3. Production CSID
        $pcsid = (new ZatcaApiClient($zatca))->requestProductionCsid($zatca->compliance_request_id);
        $zatca->fill([
            'pcsid_token' => $pcsid['binarySecurityToken'],
            'pcsid_secret' => $pcsid['secret'],
            'pcsid_requested_at' => now(),
            'onboarding_status' => 'ready',
        ])->save();

        return ['status' => 'ready', 'compliance_checks' => $checks];
    }

    /**
     * Submit signed sample documents (invoice / credit note / debit note for
     * each declared subtype) to the compliance-check endpoint.
     */
    public function runComplianceChecks(ZatcaSetting $zatca): array
    {
        $setting = Setting::where('deleted_at', '=', null)->first();
        $certificate = new Certificate($zatca->ccsid_token);
        $client = new ZatcaApiClient($zatca);

        $types = (string) ($zatca->invoice_types ?: '1100');
        $subtypes = [];
        if (($types[0] ?? '0') === '1') {
            $subtypes[] = 'standard';
        }
        if (($types[1] ?? '0') === '1') {
            $subtypes[] = 'simplified';
        }

        $results = [];
        $icv = 0;
        $pih = config('zatca.initial_pih');

        foreach ($subtypes as $subtype) {
            foreach (['388' => 'Invoice', '381' => 'Credit note', '383' => 'Debit note'] as $typeCode => $label) {
                $icv++;
                $sample = $this->sampleInvoiceData($zatca, $setting, $subtype, $typeCode, $icv, $pih);
                $unsigned = $this->xmlGenerator->generate($sample);
                $signed = $this->signer->sign($unsigned, $sample, $certificate, $zatca->private_key);

                $response = $client->complianceCheck($signed['hash'], $sample['uuid'], $signed['xml']);
                $body = $response['body'];
                $status = $body['reportingStatus'] ?? $body['clearanceStatus'] ?? ($body['validationResults']['status'] ?? null);
                $passed = in_array($response['status'], [200, 202], true);

                $results[] = [
                    'type' => $subtype.' '.strtolower($label).' ('.$typeCode.')',
                    'passed' => $passed,
                    'status' => $status,
                    'http_status' => $response['status'],
                    'messages' => $this->validationMessages($body),
                ];
            }
        }

        return $results;
    }

    // ------------------------------------------------------------------
    // Invoice processing
    // ------------------------------------------------------------------

    /**
     * Generate, sign and submit a Sale (388) or SaleReturn (381).
     * Idempotent: an already reported/cleared document is returned as-is.
     */
    public function processDocument(Model $source): ZatcaDocument
    {
        $zatca = ZatcaSetting::current();
        if (! $zatca || ! $zatca->isReady()) {
            throw new RuntimeException('ZATCA Phase 2 is not enabled or onboarding is not complete.');
        }

        $existing = ZatcaDocument::forSource($source);
        if ($existing && $existing->isAccepted()) {
            return $existing;
        }

        // Retry semantics: a document that never reached ZATCA (stranded
        // 'pending', or transport failure with no gateway response) is
        // resubmitted unchanged — its ICV/PIH were already consumed from the
        // chain. A document ZATCA *rejected* (validation errors in the
        // response) is regenerated from current data with a fresh ICV, since
        // identical XML can never pass.
        $regenerate = $existing
            && $existing->status === 'failed'
            && ! empty($existing->zatca_response);

        if ($existing && $existing->invoice_xml && ! $regenerate) {
            return $this->submit($zatca, $existing);
        }

        $setting = Setting::where('deleted_at', '=', null)->first();
        if (! $setting || empty($setting->vat_number)) {
            throw new RuntimeException('Company VAT number is not configured in System Settings.');
        }

        $certificate = new Certificate($zatca->pcsid_token);

        // Allocate ICV + PIH and persist the signed document atomically; the
        // generation chain must be strictly sequential per EGS unit.
        $document = DB::transaction(function () use ($source, $zatca, $setting, $certificate, $regenerate) {
            $locked = ZatcaSetting::query()->lockForUpdate()->find($zatca->id);

            // Re-check under the lock: a concurrent run (queued job vs manual
            // submit) may have generated this document already. Every
            // generation path takes this lock, so the check is race-free.
            $current = ZatcaDocument::forSource($source);
            if ($current && ($current->isAccepted() || ($current->invoice_xml && ! $regenerate))) {
                return $current;
            }

            $icv = (int) $locked->icv + 1;
            $pih = $locked->last_invoice_hash ?: config('zatca.initial_pih');

            $data = $this->mapper->map($source, $locked, $setting, $icv, $pih);
            if ($current && ! empty($current->uuid)) {
                // The business document keeps its UUID across regenerations.
                $data['uuid'] = $current->uuid;
            }
            $unsigned = $this->xmlGenerator->generate($data);
            $signed = $this->signer->sign($unsigned, $data, $certificate, $locked->private_key);

            if ($current && $current->invoice_xml) {
                // Preserve the superseded signed XML: its hash may already be
                // referenced as PIH by later invoices, so the audit chain must
                // stay resolvable.
                try {
                    ZatcaLog::create([
                        'zatca_document_id' => $current->id,
                        'action' => 'superseded_xml',
                        'endpoint' => null,
                        'http_status' => null,
                        'request_meta' => ['icv' => $current->icv, 'invoice_hash' => $current->invoice_hash],
                        'response_body' => $current->invoice_xml,
                    ]);
                } catch (\Throwable $e) {
                    // Archival must not block regeneration.
                }
            }

            $attributes = [
                'invoice_number' => $data['id'],
                'invoice_type_code' => $data['type_code'],
                'invoice_subtype' => $data['subtype'],
                'uuid' => $data['uuid'],
                'icv' => $icv,
                'invoice_hash' => $signed['hash'],
                'pih' => $pih,
                'qr_code' => $signed['qr'],
                'status' => 'pending',
                'environment' => $locked->environment,
                'invoice_xml' => $signed['xml'],
                'cleared_xml' => null,
                'zatca_response' => null,
                'warnings' => null,
                'errors' => null,
            ];

            if ($current) {
                $current->fill($attributes)->save();
                $document = $current;
            } else {
                $document = ZatcaDocument::create(array_merge($attributes, [
                    'source_type' => get_class($source),
                    'source_id' => $source->id,
                ]));
            }

            $locked->forceFill([
                'icv' => $icv,
                'last_invoice_hash' => $signed['hash'],
            ])->save();

            return $document;
        });

        if ($document->isAccepted()) {
            return $document;
        }

        return $this->submit($zatca, $document);
    }

    /**
     * Report or clear an already-generated document.
     */
    public function submit(ZatcaSetting $zatca, ZatcaDocument $document): ZatcaDocument
    {
        $client = new ZatcaApiClient($zatca);

        try {
            $result = $document->invoice_subtype === 'standard'
                ? $client->clearInvoice($document->invoice_hash, $document->uuid, $document->invoice_xml, $document->id)
                : $client->reportInvoice($document->invoice_hash, $document->uuid, $document->invoice_xml, $document->id);
        } catch (\Throwable $e) {
            $document->fill([
                'status' => 'failed',
                'errors' => [['message' => $e->getMessage()]],
                'submitted_at' => now(),
            ])->save();

            return $document;
        }

        $body = $result['body'];
        $http = $result['status'];
        $accepted = in_array($http, [200, 202], true);

        $update = [
            'zatca_response' => $body,
            'warnings' => $this->messagesOf($body, 'warningMessages'),
            'errors' => $this->messagesOf($body, 'errorMessages'),
            'submitted_at' => now(),
        ];

        if ($accepted && $document->invoice_subtype === 'standard') {
            $update['status'] = 'cleared';
            if (! empty($body['clearedInvoice'])) {
                $clearedXml = base64_decode($body['clearedInvoice']);
                $update['cleared_xml'] = $clearedXml;
                // ZATCA stamps its own QR into the cleared invoice; surface it.
                if (preg_match('/<cbc:ID>QR<\/cbc:ID>.*?mimeCode="text\/plain">([^<]+)</s', $clearedXml, $m)) {
                    $update['qr_code'] = trim($m[1]);
                }
            }
        } elseif ($accepted) {
            $update['status'] = 'reported';
        } else {
            $update['status'] = 'failed';
        }

        $document->fill($update)->save();

        return $document;
    }

    /**
     * Convenience wrapper with the enable/auto-submit guards, used from the
     * sale-creation hooks. Never throws.
     */
    public function processQuietly(Model $source): ?ZatcaDocument
    {
        try {
            $zatca = ZatcaSetting::current();
            if (! $zatca || ! $zatca->enabled || ! $zatca->auto_submit || ! $zatca->isReady()) {
                return null;
            }

            return $this->processDocument($source);
        } catch (\Throwable $e) {
            \Log::warning('ZATCA submission failed (non-blocking): '.$e->getMessage(), [
                'source' => get_class($source).'#'.($source->id ?? '?'),
            ]);

            return null;
        }
    }

    // ------------------------------------------------------------------

    /**
     * Sample document used for the onboarding compliance checks.
     */
    protected function sampleInvoiceData(ZatcaSetting $zatca, ?Setting $setting, string $subtype, string $typeCode, int $icv, string $pih): array
    {
        $lineNet = 100.00;
        $vat = 15.00;

        $supplier = [
            'name' => $setting->CompanyName ?? 'Company',
            'vat' => (string) ($setting->vat_number ?? ''),
            'other_id' => $zatca->crn ?: '1010010000',
            'other_id_scheme' => 'CRN',
            'street' => $zatca->street_name ?: 'King Fahd Road',
            'building' => $zatca->building_number ?: '1234',
            'sub_division' => $zatca->sub_division ?: 'Al Olaya',
            'city' => $zatca->city ?: 'Riyadh',
            'postal' => $zatca->postal_zone ?: '12211',
            'country' => 'SA',
        ];

        $customer = null;
        if ($subtype === 'standard') {
            $customer = [
                'name' => 'Sample Buyer Co.',
                'vat' => '399999999800003',
                'street' => 'Prince Sultan Street',
                'building' => '2322',
                'sub_division' => 'Al Murabba',
                'city' => 'Riyadh',
                'postal' => '23333',
                'country' => 'SA',
            ];
        }

        return [
            'id' => 'COMP-'.$icv,
            'uuid' => (string) Str::uuid(),
            'issue_date' => now()->format('Y-m-d'),
            'issue_time' => now()->format('H:i:s'),
            'type_code' => $typeCode,
            'subtype' => $subtype,
            'icv' => $icv,
            'pih' => $pih,
            'currency' => 'SAR',
            'billing_reference' => $typeCode === '388' ? null : 'COMP-1',
            'instruction_note' => $typeCode === '388' ? null : 'Compliance check adjustment',
            'payment_means_code' => '10',
            'supplier' => $supplier,
            'customer' => $customer,
            'delivery_date' => $subtype === 'standard' ? now()->format('Y-m-d') : null,
            'allowances' => [],
            'charges' => [],
            'lines' => [[
                'name' => 'Compliance check item',
                'quantity' => 1,
                'unit_code' => 'PCE',
                'unit_price' => $lineNet,
                'discount' => 0,
                'net_amount' => $lineNet,
                'tax_amount' => $vat,
                'tax_category' => 'S',
                'tax_rate' => 15,
            ]],
            'tax_subtotals' => [[
                'taxable' => $lineNet,
                'tax' => $vat,
                'category' => 'S',
                'rate' => 15,
            ]],
            'totals' => [
                'line_extension' => $lineNet,
                'tax_exclusive' => $lineNet,
                'tax_inclusive' => $lineNet + $vat,
                'allowance_total' => 0,
                'charge_total' => 0,
                'prepaid' => 0,
                'payable_rounding' => 0,
                'payable' => $lineNet + $vat,
                'tax_total' => $vat,
            ],
            'seller_name' => $setting->company_name_ar ?? ($setting->CompanyName ?? 'Company'),
            'vat_number' => (string) ($setting->vat_number ?? ''),
            'issue_datetime' => now()->format('Y-m-d\TH:i:s').'Z',
            'total_with_vat' => $lineNet + $vat,
            'vat_amount' => $vat,
        ];
    }

    protected function validationMessages(array $body): array
    {
        $messages = [];
        foreach (['infoMessages', 'warningMessages', 'errorMessages'] as $bucket) {
            foreach ((array) data_get($body, 'validationResults.'.$bucket, []) as $m) {
                $messages[] = [
                    'level' => str_replace('Messages', '', $bucket),
                    'code' => $m['code'] ?? null,
                    'message' => $m['message'] ?? null,
                ];
            }
        }

        return $messages;
    }

    protected function messagesOf(array $body, string $bucket): ?array
    {
        $messages = (array) data_get($body, 'validationResults.'.$bucket, []);

        return empty($messages) ? null : array_values($messages);
    }
}
