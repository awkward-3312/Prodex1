<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosNativeCatalogGateArchitectureTest extends TestCase
{
    public function test_native_catalog_bridge_does_not_mutate_vue_component_state(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-location-catalog.js'));

        $this->assertStringContainsString('config.__prodexPosNativeCatalog = true', $script);
        $this->assertStringContainsString("parsed.pathname = '/api/pos/location-inventory/' + ctx.inventory_location_id + '/catalog'", $script);
        $this->assertStringNotContainsString('__vue__', $script);
        $this->assertStringNotContainsString('productsReady', $script);
        $this->assertStringNotContainsString('releasePosCatalogGate', $script);
    }

    public function test_operational_chrome_patch_is_idempotent_and_animation_frame_throttled(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-location-ui.js'));

        // DOM decoration must never create its own MutationObserver feedback loop.
        $this->assertStringContainsString("eyebrow.textContent !== expectedEyebrow", $script);
        $this->assertStringContainsString("text.textContent !== expectedLabel", $script);
        $this->assertStringContainsString("drawer.style.display !== 'none'", $script);
        $this->assertStringContainsString('var chromePatchScheduled = false', $script);
        $this->assertStringContainsString('window.requestAnimationFrame(run)', $script);
        $this->assertStringContainsString('scheduleOperationalChrome()', $script);
        $this->assertStringNotContainsString('setInterval(function ()', $script);
    }

    public function test_observer_safety_fix_does_not_mutate_pos_business_state(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-location-ui.js'));

        $this->assertStringNotContainsString('cash_register_id = null', $script);
        $this->assertStringNotContainsString('cash_drawer_id = null', $script);
        $this->assertStringNotContainsString('warehouse_id = null', $script);
        $this->assertStringNotContainsString('inventory_location_id = null', $script);
    }
}
