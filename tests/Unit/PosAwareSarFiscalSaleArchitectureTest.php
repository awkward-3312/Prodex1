<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosAwareSarFiscalSaleArchitectureTest extends TestCase
{
    public function test_modern_pos_resolves_sar_by_physical_cash_drawer_without_persisting_warehouse(): void
    {
        $service = file_get_contents(base_path('app/Services/PosAwareSarFiscalSaleService.php'));

        $this->assertStringContainsString("where('cash_drawer_id', $cashDrawerId)", $service);
        $this->assertStringContainsString("(int) $drawer->branch_id !== (int) $sale->branch_id", $service);
        $this->assertStringContainsString("$originalWarehouseId = $sale->getAttribute('warehouse_id')", $service);
        $this->assertStringContainsString("$sale->setAttribute('warehouse_id', $originalWarehouseId)", $service);
        $this->assertStringContainsString('No existe un punto SAR activo para la caja física seleccionada.', $service);
    }

    public function test_sar_remains_mandatory_and_pos_aware_service_is_the_runtime_binding(): void
    {
        $provider = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
        $legacy = file_get_contents(base_path('app/Services/SarFiscalSaleService.php'));

        $this->assertStringContainsString('singleton(SarFiscalSaleService::class, PosAwareSarFiscalSaleService::class)', $provider);
        $this->assertStringContainsString("if (! $profile || ! $profile->enabled)", $legacy);
        $this->assertStringContainsString('No existe una autorización SAR activa para el punto de emisión seleccionado.', $legacy);
    }
}
