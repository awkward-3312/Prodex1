<?php

namespace Tests\Unit;

use App\Services\SarInvoiceSettings;
use PHPUnit\Framework\TestCase;

class SarInvoiceSettingsTest extends TestCase
{
    public function test_defaults_include_required_presentation_controls(): void
    {
        $settings = SarInvoiceSettings::defaults();

        $this->assertSame('FACTURA', $settings['document_title']);
        $this->assertSame('CONTADO', $settings['sale_type_label']);
        $this->assertSame('Original: Cliente', $settings['original_label']);
        $this->assertSame('Copia: Obligado Tributario Emisor', $settings['copy_label']);
        $this->assertTrue($settings['show_payment_summary']);
        $this->assertTrue($settings['show_total_in_words']);
        $this->assertTrue($settings['show_qr']);
    }

    public function test_tenant_values_override_defaults_without_losing_other_keys(): void
    {
        $settings = SarInvoiceSettings::merge([
            'sale_type_label' => 'CRÉDITO',
            'footer_message' => 'Conserve su factura.',
            'show_qr' => false,
        ]);

        $this->assertSame('FACTURA', $settings['document_title']);
        $this->assertSame('CRÉDITO', $settings['sale_type_label']);
        $this->assertSame('Conserve su factura.', $settings['footer_message']);
        $this->assertFalse($settings['show_qr']);
        $this->assertTrue($settings['show_cashier']);
    }
}
