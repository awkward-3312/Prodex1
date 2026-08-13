<?php

namespace App\Services\Zatca;

use App\Support\ZatcaQr;
use DOMDocument;
use RuntimeException;

/**
 * Signs a UBL invoice with an XAdES B-B enveloped signature and produces the
 * Phase 2 QR code, per the ZATCA "E-Invoicing Security Features
 * Implementation Standard":
 *
 *  1. Invoice hash: SHA-256 (Base64 of binary digest) over the C14N form of
 *     the invoice with UBLExtensions, cac:Signature and the QR document
 *     reference excluded (our unsigned XML is generated without them).
 *  2. Digital signature: ECDSA(secp256k1)/SHA-256 over the invoice digest.
 *  3. Certificate hash: Base64 of the *hex* SHA-256 of the Base64 cert body.
 *  4. SignedProperties digest: Base64 of the *hex* SHA-256 of the
 *     linearized xades:SignedProperties fragment.
 *  5. QR: TLV tags 1-9 Base64 encoded.
 */
class InvoiceSigner
{
    /**
     * Exact byte template hashed for the xades:SignedProperties reference.
     * Whitespace is significant — do not reformat.
     */
    protected const SIGNED_PROPERTIES_TEMPLATE =
        '<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="xadesSignedProperties">'."\n".
        '                                    <xades:SignedSignatureProperties>'."\n".
        '                                        <xades:SigningTime>SET_SIGN_TIMESTAMP</xades:SigningTime>'."\n".
        '                                        <xades:SigningCertificate>'."\n".
        '                                            <xades:Cert>'."\n".
        '                                                <xades:CertDigest>'."\n".
        '                                                    <ds:DigestMethod xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'."\n".
        '                                                    <ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">SET_CERTIFICATE_HASH</ds:DigestValue>'."\n".
        '                                                </xades:CertDigest>'."\n".
        '                                                <xades:IssuerSerial>'."\n".
        '                                                    <ds:X509IssuerName xmlns:ds="http://www.w3.org/2000/09/xmldsig#">SET_CERTIFICATE_ISSUER</ds:X509IssuerName>'."\n".
        '                                                    <ds:X509SerialNumber xmlns:ds="http://www.w3.org/2000/09/xmldsig#">SET_CERTIFICATE_SERIAL_NUMBER</ds:X509SerialNumber>'."\n".
        '                                                </xades:IssuerSerial>'."\n".
        '                                            </xades:Cert>'."\n".
        '                                        </xades:SigningCertificate>'."\n".
        '                                    </xades:SignedSignatureProperties>'."\n".
        '                                </xades:SignedProperties>';

    /**
     * Compute the ZATCA invoice hash (Base64 of the binary SHA-256 of the
     * canonicalized invoice).
     */
    public function invoiceHash(string $unsignedXml): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if (! @$dom->loadXML($unsignedXml)) {
            throw new RuntimeException('Generated invoice XML is not well-formed.');
        }

        $canonical = $dom->C14N(false, false);

        return base64_encode(hash('sha256', $canonical, true));
    }

    /**
     * Sign the invoice and build the QR code.
     *
     * @param string      $unsignedXml XML produced by UblInvoiceXmlGenerator
     * @param array       $meta        seller_name, vat_number, issue_datetime
     *                                 (ISO, e.g. 2026-07-05T10:00:00Z),
     *                                 total_with_vat, vat_amount, subtype
     * @param Certificate $certificate CSID certificate
     * @param string      $privateKeyPem
     *
     * @return array{xml: string, hash: string, qr: string, signature: string}
     */
    public function sign(string $unsignedXml, array $meta, Certificate $certificate, string $privateKeyPem): array
    {
        $invoiceHash = $this->invoiceHash($unsignedXml);

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Unable to load the EGS private key.');
        }
        if (! openssl_sign(base64_decode($invoiceHash), $signatureDer, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('ECDSA signing failed.');
        }
        $signature = base64_encode($signatureDer);

        $signingTime = gmdate('Y-m-d\TH:i:s');
        $signedProperties = str_replace(
            ['SET_SIGN_TIMESTAMP', 'SET_CERTIFICATE_HASH', 'SET_CERTIFICATE_ISSUER', 'SET_CERTIFICATE_SERIAL_NUMBER'],
            [
                $signingTime,
                $certificate->hash(),
                $this->escape($certificate->issuerName()),
                $certificate->serialNumber(),
            ],
            self::SIGNED_PROPERTIES_TEMPLATE
        );
        $signedPropertiesHash = base64_encode(hash('sha256', $signedProperties));

        $qr = $this->buildQr($meta, $invoiceHash, $signature, $certificate);

        $signedXml = $this->assemble(
            $unsignedXml,
            $invoiceHash,
            $signedPropertiesHash,
            $signature,
            $certificate->base64(),
            $signedProperties,
            $qr
        );

        return [
            'xml' => $signedXml,
            'hash' => $invoiceHash,
            'qr' => $qr,
            'signature' => $signature,
        ];
    }

    /**
     * Phase 2 QR TLV payload (tags 1-9; tag 9 for simplified invoices only).
     */
    protected function buildQr(array $meta, string $invoiceHash, string $signature, Certificate $certificate): string
    {
        $tags = [
            1 => (string) $meta['seller_name'],
            2 => (string) $meta['vat_number'],
            3 => (string) $meta['issue_datetime'],
            4 => ZatcaQr::amount((string) $meta['total_with_vat']),
            5 => ZatcaQr::amount((string) $meta['vat_amount']),
            6 => $invoiceHash,
            7 => $signature,
            8 => $certificate->publicKeyDer(),
        ];

        if (($meta['subtype'] ?? 'simplified') === 'simplified') {
            $tags[9] = $certificate->signatureBytes();
        }

        return ZatcaQr::generatePhase2($tags);
    }

    /**
     * Inject UBLExtensions, the QR document reference and cac:Signature into
     * the unsigned XML. Insertions are whitespace-tight: removing these
     * elements (as the ZATCA hashing transform does) yields the exact
     * unsigned document, keeping the invoice hash valid.
     */
    protected function assemble(
        string $xml,
        string $invoiceHash,
        string $signedPropertiesHash,
        string $signature,
        string $certificateBase64,
        string $signedProperties,
        string $qr
    ): string {
        $ubl = $this->ublExtensions($invoiceHash, $signedPropertiesHash, $signature, $certificateBase64, $signedProperties);

        // 1. UBLExtensions immediately after the root element's opening tag.
        $rootEnd = strpos($xml, '>', strpos($xml, '<Invoice'));
        if ($rootEnd === false) {
            throw new RuntimeException('Invoice root element not found.');
        }
        $xml = substr($xml, 0, $rootEnd + 1).$ubl.substr($xml, $rootEnd + 1);

        // 2. QR reference + cac:Signature immediately after the PIH block.
        $pihId = '<cbc:ID>PIH</cbc:ID>';
        $pihPos = strpos($xml, $pihId);
        if ($pihPos === false) {
            throw new RuntimeException('PIH document reference not found.');
        }
        $closeTag = '</cac:AdditionalDocumentReference>';
        $closePos = strpos($xml, $closeTag, $pihPos);
        if ($closePos === false) {
            throw new RuntimeException('PIH document reference is malformed.');
        }
        $insertAt = $closePos + strlen($closeTag);

        $qrAndSignature =
            '<cac:AdditionalDocumentReference>'."\n".
            '        <cbc:ID>QR</cbc:ID>'."\n".
            '        <cac:Attachment>'."\n".
            '            <cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.$qr.'</cbc:EmbeddedDocumentBinaryObject>'."\n".
            '        </cac:Attachment>'."\n".
            '    </cac:AdditionalDocumentReference><cac:Signature>'."\n".
            '        <cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID>'."\n".
            '        <cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod>'."\n".
            '    </cac:Signature>';

        return substr($xml, 0, $insertAt).$qrAndSignature.substr($xml, $insertAt);
    }

    /**
     * The ext:UBLExtensions signature envelope. The embedded
     * xades:SignedProperties bytes are exactly the hashed template so any
     * extraction-based digest verification reproduces the same digest.
     */
    protected function ublExtensions(
        string $invoiceHash,
        string $signedPropertiesHash,
        string $signature,
        string $certificateBase64,
        string $signedProperties
    ): string {
        return '<ext:UBLExtensions>'."\n".
'        <ext:UBLExtension>'."\n".
'            <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>'."\n".
'            <ext:ExtensionContent>'."\n".
'                <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">'."\n".
'                    <sac:SignatureInformation>'."\n".
'                        <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>'."\n".
'                        <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'."\n".
'                        <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">'."\n".
'                            <ds:SignedInfo>'."\n".
'                                <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'."\n".
'                                <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'."\n".
'                                <ds:Reference Id="invoiceSignedData" URI="">'."\n".
'                                    <ds:Transforms>'."\n".
'                                        <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">'."\n".
'                                            <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>'."\n".
'                                        </ds:Transform>'."\n".
'                                        <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">'."\n".
'                                            <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>'."\n".
'                                        </ds:Transform>'."\n".
'                                        <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">'."\n".
'                                            <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath>'."\n".
'                                        </ds:Transform>'."\n".
'                                        <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'."\n".
'                                    </ds:Transforms>'."\n".
'                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'."\n".
'                                    <ds:DigestValue>'.$invoiceHash.'</ds:DigestValue>'."\n".
'                                </ds:Reference>'."\n".
'                                <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">'."\n".
'                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'."\n".
'                                    <ds:DigestValue>'.$signedPropertiesHash.'</ds:DigestValue>'."\n".
'                                </ds:Reference>'."\n".
'                            </ds:SignedInfo>'."\n".
'                            <ds:SignatureValue>'.$signature.'</ds:SignatureValue>'."\n".
'                            <ds:KeyInfo>'."\n".
'                                <ds:X509Data>'."\n".
'                                    <ds:X509Certificate>'.$certificateBase64.'</ds:X509Certificate>'."\n".
'                                </ds:X509Data>'."\n".
'                            </ds:KeyInfo>'."\n".
'                            <ds:Object>'."\n".
'                                <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">'."\n".
$signedProperties."\n".
'                                </xades:QualifyingProperties>'."\n".
'                            </ds:Object>'."\n".
'                        </ds:Signature>'."\n".
'                    </sac:SignatureInformation>'."\n".
'                </sig:UBLDocumentSignatures>'."\n".
'            </ext:ExtensionContent>'."\n".
'        </ext:UBLExtension>'."\n".
'    </ext:UBLExtensions>';
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
