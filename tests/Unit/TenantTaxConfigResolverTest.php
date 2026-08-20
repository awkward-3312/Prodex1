<?php

namespace Tests\Unit;

use App\Services\TenantTaxConfigResolver;
use PHPUnit\Framework\TestCase;

class TenantTaxConfigResolverTest extends TestCase
{
    public function test_honduras_supports_line_level_sar_rates(): void
    {
        $config = TenantTaxConfigResolver::defaultForCountry('HN');

        $this->assertSame('HN', $config['country_code']);
        $this->assertSame('ISV', $config['tax_name']);
        $this->assertSame('SAR', $config['tax_regime_code']);
        $this->assertTrue($config['supports_line_tax']);
        $this->assertSame([0.0, 15.0, 18.0], $config['tax_rates']);
        $this->assertSame(15.0, $config['tax_rate']);
    }

    public function test_honduras_tax_configuration_is_returned_by_resolver(): void
    {
        $config = TenantTaxConfigResolver::resolve([
            'country_code' => 'HN',
            'tax_rate' => 15,
        ], 'HN');

        $this->assertSame('HN', $config['country_code']);
        $this->assertTrue($config['supports_line_tax']);
        $this->assertSame([0.0, 15.0, 18.0], $config['tax_rates']);
        $this->assertSame('RTN', $config['customer_tax_id_label']);
    }
}
