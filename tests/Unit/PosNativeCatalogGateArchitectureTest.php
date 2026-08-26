<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosNativeCatalogGateArchitectureTest extends TestCase
{
    public function test_native_catalog_bridge_releases_only_the_pos_product_gate(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-location-catalog.js'));

        $this->assertStringContainsString('function releasePosCatalogGate()', $script);
        $this->assertStringContainsString("document.querySelector('.pos-codecanyon')", $script);
        $this->assertStringContainsString("hasOwnProperty.call(vm.\$data, 'productsReady')", $script);
        $this->assertStringContainsString('vm.productsReady = true', $script);
        $this->assertStringContainsString('config.__prodexPosNativeCatalog', $script);
        $this->assertStringContainsString('isNativeCatalog(config)', $script);
    }

    public function test_gate_recovery_does_not_mutate_cash_register_or_stock_business_state(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-location-catalog.js'));

        // Keep this recovery strictly visual: operational state remains owned by POS services/controllers.
        $this->assertStringNotContainsString('cash_register_id =', $script);
        $this->assertStringNotContainsString('cash_drawer_id = null', $script);
        $this->assertStringNotContainsString('warehouse_id = null', $script);
        $this->assertStringNotContainsString('inventory_location_id = null', $script);
    }
}
