<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\TenantTaxConfigResolver;
use Tests\TestCase;

class TenantTaxConfigResolverTest extends TestCase
{
    public function test_it_resolves_honduras_defaults_when_no_country_profile_is_set(): void
    {
        $setting = Setting::factory()->create([
            'country_code' => null,
            'tax_regime_code' => null,
            'tax_rate' => null,
            'locale' => null,
        ]);

        $resolved = TenantTaxConfigResolver::resolve($setting);

        $this->assertSame('HN', $resolved['country_code']);
        $this->assertSame('SAR', $resolved['tax_regime_code']);
        $this->assertSame(15.0, $resolved['tax_rate']);
        $this->assertSame('es-HN', $resolved['locale']);
    }

    public function test_it_resolves_mexico_settings_when_country_is_mx(): void
    {
        $setting = Setting::factory()->create([
            'country_code' => 'MX',
            'tax_regime_code' => 'IVA',
            'tax_rate' => 16,
            'locale' => 'es-MX',
        ]);

        $resolved = TenantTaxConfigResolver::resolve($setting);

        $this->assertSame('MX', $resolved['country_code']);
        $this->assertSame('IVA', $resolved['tax_regime_code']);
        $this->assertSame(16.0, $resolved['tax_rate']);
        $this->assertSame('es-MX', $resolved['locale']);
    }

    public function test_it_prioritizes_the_tenant_country_over_a_conflicting_setting_country(): void
    {
        $setting = Setting::factory()->create([
            'country_code' => 'MX',
            'tax_rate' => 16,
            'legal_document_label' => 'RFC',
        ]);

        $resolved = TenantTaxConfigResolver::resolve($setting, 'HN');

        $this->assertSame('HN', $resolved['country_code']);
        $this->assertSame('ISV', $resolved['tax_name']);
        $this->assertSame(15.0, $resolved['tax_rate']);
        $this->assertSame('RTN', $resolved['customer_tax_id_label']);
    }
}
