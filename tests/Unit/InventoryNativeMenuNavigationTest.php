<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InventoryNativeMenuNavigationTest extends TestCase
{
    public function test_inventory_native_menu_resolves_router_from_sidebar_vue_instance(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/resources/static/prodex-inventory-native-menu.js');

        $this->assertStringContainsString("closest('.vertical-sidebar-wrapper')", $script);
        $this->assertStringContainsString("document.querySelector('.vertical-sidebar')", $script);
        $this->assertStringContainsString('event.preventDefault()', $script);
        $this->assertStringContainsString('router.push(entry[1])', $script);
    }
}
