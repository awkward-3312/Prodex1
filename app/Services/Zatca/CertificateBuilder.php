<?php

namespace App\Services\Zatca;

use App\Models\Setting;
use App\Models\ZatcaSetting;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates the ECDSA secp256k1 key pair and the ZATCA-conformant CSR
 * (Certificate Signing Request) used for CSID onboarding, following the
 * "E-Invoicing Security Features Implementation Standard":
 *
 *  - Key algorithm: ECDSA on curve secp256k1
 *  - Subject: C, OU, O, CN
 *  - Custom extension 1.3.6.1.4.1.311.20.2 (certificateTemplateName):
 *      TSTZATCA-Code-Signing / PREZATCA-Code-Signing / ZATCA-Code-Signing
 *  - subjectAltName dirName: SN (EGS serial 1-..|2-..|3-..), UID (VAT number),
 *      title (invoice type functionality map), registeredAddress, businessCategory
 */
class CertificateBuilder
{
    /**
     * Generate a new EC key pair + CSR for the given tenant settings.
     *
     * @return array{private_key: string, csr: string} PEM strings
     */
    public function build(ZatcaSetting $zatca, ?Setting $setting = null): array
    {
        $setting = $setting ?: Setting::where('deleted_at', '=', null)->first();

        $vat = trim((string) ($setting->vat_number ?? ''));
        if (! preg_match('/^3\d{13}3$/', $vat)) {
            throw new RuntimeException('A valid 15-digit VAT registration number (starting and ending with 3) must be set in System Settings before onboarding.');
        }

        $organization = $zatca->organization_name ?: ($setting->CompanyName ?: 'Company');
        $commonName = $zatca->common_name ?: ($organization.'-'.substr($vat, 0, 10));
        $orgUnit = $zatca->organization_unit ?: $organization;
        $serial = sprintf(
            '1-%s|2-%s|3-%s',
            $zatca->solution_name ?: 'Rasheed',
            $zatca->solution_version ?: '1.0',
            $zatca->device_serial ?: (string) Str::uuid()
        );
        $address = trim(implode(' ', array_filter([
            $zatca->building_number, $zatca->street_name, $zatca->sub_division, $zatca->city,
        ]))) ?: (string) ($setting->CompanyAdress ?: 'Riyadh');
        $category = $zatca->business_category ?: 'Trading';
        $invoiceTypes = preg_match('/^[01]{4}$/', (string) $zatca->invoice_types) ? $zatca->invoice_types : '1100';

        $configFile = $this->writeOpenSslConfig(
            $zatca->csrTemplate(),
            $serial,
            $vat,
            $invoiceTypes,
            $address,
            $category
        );

        try {
            $keyResource = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'secp256k1',
                'config' => $configFile,
            ]);
            if ($keyResource === false) {
                throw new RuntimeException('Failed generating EC key pair: '.$this->opensslErrors());
            }

            if (! openssl_pkey_export($keyResource, $privateKeyPem, null, ['config' => $configFile])) {
                throw new RuntimeException('Failed exporting private key: '.$this->opensslErrors());
            }

            $dn = [
                'countryName' => strtoupper($zatca->country_code ?: 'SA'),
                'organizationalUnitName' => $orgUnit,
                'organizationName' => $organization,
                'commonName' => $commonName,
            ];

            $csrResource = openssl_csr_new($dn, $keyResource, [
                'digest_alg' => 'sha256',
                'config' => $configFile,
                'req_extensions' => 'req_ext',
            ]);
            if ($csrResource === false) {
                throw new RuntimeException('Failed generating CSR: '.$this->opensslErrors());
            }

            if (! openssl_csr_export($csrResource, $csrPem)) {
                throw new RuntimeException('Failed exporting CSR: '.$this->opensslErrors());
            }
        } finally {
            @unlink($configFile);
        }

        return ['private_key' => $privateKeyPem, 'csr' => $csrPem];
    }

    /**
     * Write a temporary OpenSSL config carrying the ZATCA CSR extensions.
     */
    protected function writeOpenSslConfig(
        string $template,
        string $serial,
        string $vat,
        string $invoiceTypes,
        string $address,
        string $category
    ): string {
        $esc = fn (string $v): string => str_replace(["\r", "\n", '"'], ['', '', ''], $v);

        $config = <<<CNF
oid_section = OIDs

[OIDs]
certificateTemplateName = 1.3.6.1.4.1.311.20.2

[req]
default_bits = 2048
prompt = no
default_md = sha256
req_extensions = req_ext
distinguished_name = dn

[dn]
C = SA
CN = placeholder

[req_ext]
certificateTemplateName = ASN1:PRINTABLESTRING:{$esc($template)}
subjectAltName = dirName:alt_names

[alt_names]
SN = {$esc($serial)}
UID = {$esc($vat)}
title = {$esc($invoiceTypes)}
registeredAddress = {$esc($address)}
businessCategory = {$esc($category)}
CNF;

        $path = tempnam(sys_get_temp_dir(), 'zatca_cnf_');
        if ($path === false || file_put_contents($path, $config) === false) {
            throw new RuntimeException('Unable to write temporary OpenSSL configuration.');
        }

        return $path;
    }

    protected function opensslErrors(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }

        return implode('; ', $errors) ?: 'unknown OpenSSL error';
    }
}
