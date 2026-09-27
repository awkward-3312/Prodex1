<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InventoryNativeMenuNavigationTest extends TestCase
{
    public function test_inventory_native_menu_navigates_through_the_explicit_app_bridge(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/resources/static/prodex-inventory-native-menu.js');

        // La navegación SPA ya no lee la instancia interna de Vue del DOM: usa el puente explícito de la app.
        $this->assertStringContainsString('window.__prodexBridge', $script);
        $this->assertStringContainsString('event.preventDefault()', $script);
        $this->assertStringContainsString('bridge.navigate(entry[1])', $script);
        $this->assertStringNotContainsString('__vue__', $script);
    }
}
