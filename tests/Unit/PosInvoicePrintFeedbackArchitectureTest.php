<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosInvoicePrintFeedbackArchitectureTest extends TestCase
{
    private function modalSource(): string
    {
        return file_get_contents(base_path('resources/src/views/app/components/ModernPaymentModal.vue'));
    }

    public function test_checkout_surfaces_backend_messages_instead_of_masking_them_as_network_errors(): void
    {
        $source = $this->modalSource();

        $this->assertStringContainsString('getApiErrorMessage(error', $source);
        $this->assertStringContainsString('isTrueNetworkError(error)', $source);
        $this->assertStringContainsString('const isNetworkError = this.isTrueNetworkError(error);', $source);
        $this->assertStringNotContainsString("const isNetworkError = !error.response || error.message === 'Network Error';", $source);
    }

    public function test_successful_pos_checkout_provides_invoice_print_feedback(): void
    {
        $source = $this->modalSource();

        $this->assertStringContainsString('notifyInvoicePrintStarted()', $source);
        $this->assertStringContainsString('Factura generada y enviada al flujo de impresión.', $source);
        $this->assertStringContainsString('Factura enviada correctamente a la impresora.', $source);
    }

    public function test_browser_print_failures_are_actionable_and_do_not_claim_physical_printing(): void
    {
        $source = $this->modalSource();

        $this->assertStringContainsString('el navegador bloqueó la ventana de impresión', $source);
        $this->assertStringContainsString('Puedes reimprimirla desde ventas.', $source);
        $this->assertStringContainsString('no fue posible abrir el diálogo de impresión', $source);
    }
}
