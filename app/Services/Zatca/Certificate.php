<?php

namespace App\Services\Zatca;

use RuntimeException;

/**
 * Wraps a CSID X.509 certificate (the base64 "binarySecurityToken" returned
 * by ZATCA) and exposes the pieces needed for XAdES signing and the
 * Phase 2 QR code (tags 8 and 9).
 */
class Certificate
{
    /** Raw base64 certificate body (single line, no PEM armor). */
    protected string $base64;

    /** Parsed openssl_x509_parse() output. */
    protected array $parsed;

    /**
     * @param string $binarySecurityToken The token as returned by ZATCA
     *        (base64 of the base64 certificate body) or a plain base64 cert.
     */
    public function __construct(string $binarySecurityToken)
    {
        $token = trim($binarySecurityToken);

        // ZATCA returns base64(base64(cert DER)). Decoding once yields the
        // printable base64 certificate body; if the token is already the
        // printable body, use it as-is.
        $decoded = base64_decode($token, true);
        if ($decoded !== false && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $decoded) && str_starts_with(ltrim($decoded), 'MII')) {
            $this->base64 = preg_replace('/\s+/', '', $decoded);
        } elseif (str_starts_with($token, 'MII')) {
            $this->base64 = preg_replace('/\s+/', '', $token);
        } else {
            throw new RuntimeException('Unrecognized certificate token format.');
        }

        $parsed = openssl_x509_parse($this->pem());
        if ($parsed === false) {
            throw new RuntimeException('Unable to parse the CSID certificate.');
        }
        $this->parsed = $parsed;
    }

    /** Base64 body of the certificate (no armor, no whitespace). */
    public function base64(): string
    {
        return $this->base64;
    }

    public function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($this->base64, 64, "\n")
            .'-----END CERTIFICATE-----';
    }

    public function der(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Certificate hash as mandated by ZATCA: base64 of the *hex* SHA-256
     * of the base64 certificate body.
     */
    public function hash(): string
    {
        return base64_encode(hash('sha256', $this->base64));
    }

    /**
     * Issuer distinguished name in RFC 2253 order (CN first).
     */
    public function issuerName(): string
    {
        $parts = [];
        foreach ($this->parsed['issuer'] ?? [] as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = $key.'='.$v;
            }
        }

        return implode(', ', array_reverse($parts));
    }

    /**
     * Certificate serial number as a decimal string.
     */
    public function serialNumber(): string
    {
        $serial = (string) ($this->parsed['serialNumber'] ?? '');
        if ($serial !== '' && ctype_digit($serial)) {
            return $serial;
        }

        $hex = (string) ($this->parsed['serialNumberHex'] ?? '');
        if ($hex === '' && str_starts_with($serial, '0x')) {
            $hex = substr($serial, 2);
        }
        if ($hex === '') {
            throw new RuntimeException('Unable to determine certificate serial number.');
        }

        return $this->hexToDecimal($hex);
    }

    /**
     * DER-encoded SubjectPublicKeyInfo bytes (QR tag 8).
     */
    public function publicKeyDer(): string
    {
        $key = openssl_pkey_get_public($this->pem());
        if ($key === false) {
            throw new RuntimeException('Unable to read certificate public key.');
        }
        $details = openssl_pkey_get_details($key);
        $pem = $details['key'] ?? '';
        $body = preg_replace('/-----.*?-----|\s+/', '', $pem);

        return base64_decode($body);
    }

    /**
     * Raw ECDSA signature bytes of the certificate itself (QR tag 9),
     * i.e. the top-level BIT STRING of the X.509 structure, extracted
     * with a minimal DER walk: Certificate ::= SEQUENCE {
     *   tbsCertificate, signatureAlgorithm, signatureValue BIT STRING }.
     */
    public function signatureBytes(): string
    {
        $der = $this->der();
        $pos = 0;

        [$tag, $len, $header] = $this->readTlvHeader($der, $pos);
        if ($tag !== 0x30) {
            throw new RuntimeException('Malformed certificate DER.');
        }
        $pos += $header; // enter top-level SEQUENCE

        $end = $pos + $len;
        $children = [];
        while ($pos < $end && count($children) < 3) {
            [$cTag, $cLen, $cHeader] = $this->readTlvHeader($der, $pos);
            $children[] = ['tag' => $cTag, 'start' => $pos + $cHeader, 'len' => $cLen];
            $pos += $cHeader + $cLen;
        }

        $sig = end($children);
        if ($sig === false || $sig['tag'] !== 0x03) {
            throw new RuntimeException('Certificate signature BIT STRING not found.');
        }

        // First content byte of a BIT STRING is the unused-bits count.
        return substr($der, $sig['start'] + 1, $sig['len'] - 1);
    }

    /**
     * @return array{0:int,1:int,2:int} [tag, length, header byte count]
     */
    protected function readTlvHeader(string $der, int $pos): array
    {
        if (! isset($der[$pos + 1])) {
            throw new RuntimeException('Truncated DER structure.');
        }
        $tag = ord($der[$pos]);
        $first = ord($der[$pos + 1]);
        if ($first < 0x80) {
            return [$tag, $first, 2];
        }

        $numBytes = $first & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            if (! isset($der[$pos + 2 + $i])) {
                throw new RuntimeException('Truncated DER length.');
            }
            $len = ($len << 8) | ord($der[$pos + 2 + $i]);
        }

        return [$tag, $len, 2 + $numBytes];
    }

    protected function hexToDecimal(string $hex): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $hex));
        $dec = '0';
        if (function_exists('bcmul')) {
            foreach (str_split($hex) as $digit) {
                $dec = bcadd(bcmul($dec, '16'), (string) hexdec($digit));
            }

            return $dec;
        }

        // Manual big-int base conversion fallback (string arithmetic).
        foreach (str_split($hex) as $digit) {
            $carry = hexdec($digit);
            $out = '';
            for ($i = strlen($dec) - 1; $i >= 0; $i--) {
                $v = ((int) $dec[$i]) * 16 + $carry;
                $out = ($v % 10).$out;
                $carry = intdiv($v, 10);
            }
            while ($carry > 0) {
                $out = ($carry % 10).$out;
                $carry = intdiv($carry, 10);
            }
            $dec = ltrim($out, '0') ?: '0';
        }

        return $dec;
    }
}
