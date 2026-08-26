<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosOptionalCashDrawerBridgeTest extends TestCase
{
    public function test_optional_cash_drawer_bridge_is_loaded_and_built(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-optional-cash-drawer.js'));
        $layout = file_get_contents(base_path('resources/views/layouts/master.blade.php'));
        $mix = file_get_contents(base_path('webpack.mix.js'));

        $this->assertStringContainsString("$options.name === 'ModernPaymentModal'", $script);
        $this->assertStringContainsString('vm.ensureCashDrawerAssigned = function ()', $script);
        $this->assertStringContainsString('return true;', $script);
        $this->assertStringContainsString('prodex-pos-optional-cash-drawer.js', $layout);
        $this->assertStringContainsString('prodex-pos-optional-cash-drawer.js', $mix);
    }
}
