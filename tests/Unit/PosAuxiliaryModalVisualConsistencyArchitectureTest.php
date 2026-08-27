<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosAuxiliaryModalVisualConsistencyArchitectureTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(base_path('resources/src/mixins/posKeyboardShortcuts.js'));
    }

    public function test_register_and_quick_customer_modals_share_pos_visual_language(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('#OpenRegisterModal___BV_modal_content', $source);
        $this->assertStringContainsString('#CloseRegisterModal___BV_modal_content', $source);
        $this->assertStringContainsString('#Quick_Add_Customer___BV_modal_content', $source);
        $this->assertStringContainsString('border-radius: 14px !important', $source);
        $this->assertStringContainsString('background: #6f53d9 !important', $source);
        $this->assertStringContainsString('box-shadow: 0 0 0 3px rgba(111,83,217,.10)', $source);
    }

    public function test_sar_sale_data_uses_the_same_modal_visual_language(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('customClass:{ popup:"prodex-sar-popup" }', $source);
        $this->assertStringContainsString('prodex-sar-field', $source);
        $this->assertStringContainsString('prodex-sar-help', $source);
        $this->assertStringContainsString('button.classList.toggle("is-configured",active)', $source);
        $this->assertStringNotContainsString('button.style.cssText=', $source);
    }

    public function test_visual_layer_is_installed_without_changing_pos_business_flow(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('ensurePosAuxiliaryStyles()', $source);
        $this->assertStringContainsString('this.$bvModal.show("Quick_Add_Customer")', file_get_contents(base_path('resources/src/views/app/pages/pos.vue')));
        $this->assertStringContainsString('this.$swal({', $source);
        $this->assertStringContainsString('preConfirm:()=>({', $source);
    }
}
