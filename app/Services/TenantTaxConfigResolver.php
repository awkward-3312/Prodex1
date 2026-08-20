<?php

namespace App\Services;

use App\Models\Setting;

class TenantTaxConfigResolver
{
    /**
     * Default country rules used when the tenant has no custom country config yet.
     * The platform remains multi-country, but these are safe production defaults
     * for a local-first deployment in Honduras while keeping a path for Mexico and others.
     */
    public const COUNTRY_RULES = [
        'HN' => [
            'country_code' => 'HN',
            'currency_code' => 'HNL',
            'locale' => 'es-HN',
            'timezone' => 'America/Tegucigalpa',
            'tax_name' => 'ISV',
            'tax_regime_code' => 'SAR',
            'tax_rate' => 15.0,
            'tax_rates' => [0.0, 15.0, 18.0],
            'supports_line_tax' => true,
            'legal_document_label' => 'RTN',
            'customer_tax_id_label' => 'RTN',
            'require_rtn' => true,
            'require_rfc' => false,
            'require_nit' => false,
        ],
        'MX' => [
            'country_code' => 'MX',
            'currency_code' => 'MXN',
            'locale' => 'es-MX',
            'timezone' => 'America/Mexico_City',
            'tax_name' => 'IVA',
            'tax_regime_code' => 'IVA',
            'tax_rate' => 16.0,
            'tax_rates' => [0.0, 16.0],
            'supports_line_tax' => true,
            'legal_document_label' => 'RFC',
            'customer_tax_id_label' => 'RFC',
            'require_rtn' => false,
            'require_rfc' => true,
            'require_nit' => false,
        ],
        'GT' => [
            'country_code' => 'GT',
            'currency_code' => 'GTQ',
            'locale' => 'es-GT',
            'timezone' => 'America/Guatemala',
            'tax_name' => 'IVA',
            'tax_regime_code' => 'IVA',
            'tax_rate' => 12.0,
            'tax_rates' => [0.0, 12.0],
            'supports_line_tax' => true,
            'legal_document_label' => 'NIT',
            'customer_tax_id_label' => 'NIT',
            'require_rtn' => false,
            'require_rfc' => false,
            'require_nit' => true,
        ],
        'SV' => [
            'country_code' => 'SV',
            'currency_code' => 'USD',
            'locale' => 'es-SV',
            'timezone' => 'America/El_Salvador',
            'tax_name' => 'IVA',
            'tax_regime_code' => 'IVA',
            'tax_rate' => 13.0,
            'tax_rates' => [0.0, 13.0],
            'supports_line_tax' => true,
            'legal_document_label' => 'NIT',
            'customer_tax_id_label' => 'NIT',
            'require_rtn' => false,
            'require_rfc' => false,
            'require_nit' => true,
        ],
    ];

    public static function resolve($setting, ?string $tenantCountry = null): array
    {
        $countryCode = self::resolveCountryCode($setting, $tenantCountry);
        $defaults = self::defaultForCountry($countryCode);

        // When a tenant country is explicitly provided and differs from setting country_code,
        // prioritize the tenant country's defaults (don't override with setting values)
        $settingCountry = self::read($setting, 'country_code', null);
        $useSettingOverrides = ($tenantCountry === null || strtoupper((string) $tenantCountry) === strtoupper((string) $settingCountry));

        $taxName = $useSettingOverrides ? self::read($setting, 'tax_name', $defaults['tax_name']) : $defaults['tax_name'];
        $taxRegimeCode = $useSettingOverrides ? self::read($setting, 'tax_regime_code', $defaults['tax_regime_code']) : $defaults['tax_regime_code'];
        $taxRate = $useSettingOverrides ? self::readFloat($setting, 'tax_rate', $defaults['tax_rate']) : $defaults['tax_rate'];
        $currencyCode = $useSettingOverrides ? self::read($setting, 'currency_code', $defaults['currency_code']) : $defaults['currency_code'];
        $locale = $useSettingOverrides ? self::read($setting, 'locale', $defaults['locale']) : $defaults['locale'];
        $timezone = $useSettingOverrides ? self::read($setting, 'timezone', $defaults['timezone']) : $defaults['timezone'];
        $legalDocumentLabel = $useSettingOverrides ? self::read($setting, 'legal_document_label', $defaults['legal_document_label']) : $defaults['legal_document_label'];
        $customerTaxIdLabel = $useSettingOverrides ? self::read($setting, 'customer_tax_id_label', $defaults['customer_tax_id_label']) : $defaults['customer_tax_id_label'];

        return [
            'country_code' => strtoupper((string) $countryCode),
            'currency_code' => strtoupper((string) $currencyCode),
            'locale' => (string) $locale,
            'timezone' => (string) $timezone,
            'tax_name' => strtoupper((string) $taxName),
            'tax_regime_code' => strtoupper((string) $taxRegimeCode),
            'tax_rate' => (float) $taxRate,
            'tax_rates' => array_values(array_map('floatval', $defaults['tax_rates'] ?? [0.0, (float) $taxRate])),
            'supports_line_tax' => (bool) ($defaults['supports_line_tax'] ?? false),
            'legal_document_label' => (string) $legalDocumentLabel,
            'customer_tax_id_label' => (string) $customerTaxIdLabel,
            'require_rtn' => self::readBool($setting, 'require_rtn', $defaults['require_rtn']),
            'require_rfc' => self::readBool($setting, 'require_rfc', $defaults['require_rfc']),
            'require_nit' => self::readBool($setting, 'require_nit', $defaults['require_nit']),
        ];
    }

    public static function defaultForCountry(?string $countryCode): array
    {
        $country = strtoupper((string) ($countryCode ?: 'HN'));

        return self::COUNTRY_RULES[$country] ?? self::COUNTRY_RULES['HN'];
    }

    protected static function resolveCountryCode($setting, ?string $tenantCountry = null): string
    {
        if ($tenantCountry !== null && trim((string) $tenantCountry) !== '') {
            return strtoupper((string) $tenantCountry);
        }

        $countryCode = self::read($setting, 'country_code', null);

        if ($countryCode !== null && trim((string) $countryCode) !== '') {
            return strtoupper((string) $countryCode);
        }

        return 'HN';
    }

    protected static function read($setting, string $key, $default = null)
    {
        if (is_array($setting) && array_key_exists($key, $setting)) {
            return $setting[$key];
        }

        if (is_object($setting) && isset($setting->{$key})) {
            return $setting->{$key};
        }

        if ($setting instanceof Setting) {
            return $setting->getAttribute($key) ?? $default;
        }

        return $default;
    }

    protected static function readFloat($setting, string $key, float $default): float
    {
        $value = self::read($setting, $key, $default);

        if ($value === null || $value === '') {
            return (float) $default;
        }

        return (float) $value;
    }

    protected static function readBool($setting, string $key, bool $default): bool
    {
        $value = self::read($setting, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return (bool) $value;
    }
}
