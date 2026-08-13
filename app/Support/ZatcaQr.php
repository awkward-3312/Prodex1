<?php

namespace App\Support;

use Carbon\Carbon;

class ZatcaQr
{
    /**
     * Build a TLV segment with tag and UTF-8 value.
     */
    protected static function tlv(int $tag, string $value): string
    {
        $utf8 = self::clampBytes(mb_convert_encoding($value ?? '', 'UTF-8', 'UTF-8'));
        $len = strlen($utf8); // bytes length

        return chr($tag).chr($len).$utf8;
    }

    /**
     * The TLV length field is a single byte, so values are capped at 255
     * bytes. Text values are cut on a UTF-8 codepoint boundary so a long
     * Arabic seller name cannot produce invalid UTF-8 in the QR payload.
     */
    protected static function clampBytes(string $value): string
    {
        if (strlen($value) <= 255) {
            return $value;
        }

        return mb_check_encoding($value, 'UTF-8')
            ? mb_strcut($value, 0, 255, 'UTF-8')
            : substr($value, 0, 255);
    }

    /**
     * Generate Base64 of ZATCA Phase 1 TLV payload.
     * Fields: 1 Seller Name, 2 VAT Number, 3 Timestamp (ISO 8601), 4 Total With VAT, 5 VAT Amount
     */
    public static function generate(string $sellerName, string $vatNumber, string $timestampIso8601, string $totalWithVat, string $vatAmount): string
    {
        $payload =
            self::tlv(1, $sellerName).
            self::tlv(2, $vatNumber).
            self::tlv(3, $timestampIso8601).
            self::tlv(4, self::normalizeAmount($totalWithVat)).
            self::tlv(5, self::normalizeAmount($vatAmount));

        return base64_encode($payload);
    }

    /**
     * Generate Base64 of a ZATCA Phase 2 TLV payload (tags 1-9).
     *
     * Tags 1-5 are the Phase 1 fields; Phase 2 adds:
     *   6 invoice hash (Base64 string), 7 ECDSA signature (Base64 string),
     *   8 public key (raw DER bytes), 9 certificate signature (raw bytes,
     *   simplified invoices only).
     *
     * @param array<int, string> $tags tag => value (values 6-7 as Base64
     *        strings, 8-9 as raw binary)
     */
    public static function generatePhase2(array $tags): string
    {
        $payload = '';
        ksort($tags);
        foreach ($tags as $tag => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $payload .= self::tlvBinary((int) $tag, (string) $value);
        }

        return base64_encode($payload);
    }

    /**
     * Binary-safe TLV segment (no UTF-8 re-encoding; values may be raw bytes).
     * Byte values longer than 255 are truncated per the single-byte length
     * encoding mandated by the ZATCA QR specification.
     */
    protected static function tlvBinary(int $tag, string $value): string
    {
        $value = self::clampBytes($value);

        return chr($tag).chr(strlen($value)).$value;
    }

    /**
     * Normalize an amount for use in QR tags 4/5.
     */
    public static function amount(string $amount): string
    {
        return self::normalizeAmount($amount);
    }

    /**
     * Convert amount strings to normalized 2-decimal dot format.
     */
    protected static function normalizeAmount(string $amount): string
    {
        // Remove thousands separators and unify decimals
        $normalized = str_replace([',', ' '], ['', ''], $amount);
        if (! is_numeric($normalized)) {
            // Fallback for locales using comma
            $normalized = str_replace(',', '.', $amount);
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    /**
     * Ensure a timestamp string is ISO 8601 (with timezone).
     */
    public static function toIso8601(string $dateTime, ?string $tz = null): string
    {
        $carbon = $tz ? Carbon::parse($dateTime, $tz) : Carbon::parse($dateTime);

        return $carbon->toIso8601String();
    }
}





