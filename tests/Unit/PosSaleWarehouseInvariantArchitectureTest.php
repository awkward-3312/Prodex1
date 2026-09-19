<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosSaleWarehouseInvariantArchitectureTest extends TestCase
{
    /** Ruta desde este archivo: este TestCase no arranca la app, así que base_path() no existe si el test corre solo. */
    private function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    public function test_location_native_pos_sale_cannot_be_rewritten_with_synthetic_warehouse_id(): void
    {
        $sale = file_get_contents($this->projectPath('app/Models/Sale.php'));

        // Comillas simples: con comillas dobles PHP interpolaba `$sale` (el contenido del archivo) dentro del literal
        // y la aserción buscaba un texto que nunca puede existir en Sale.php.
        $this->assertStringContainsString('$sale->isDirty(\'warehouse_id\')', $sale);
        $this->assertStringContainsString('$sale->warehouse_id = $sale->getOriginal(\'warehouse_id\')', $sale);
        $this->assertStringContainsString('A location-native POS sale must never be re-contaminated', $sale);
    }

    public function test_location_aware_batch_and_serial_services_are_bound_for_pos(): void
    {
        $provider = file_get_contents($this->projectPath('app/Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('BatchService::class, LocationAwareBatchService::class', $provider);
        $this->assertStringContainsString('SerialNumberService::class, LocationAwareSerialNumberService::class', $provider);
    }
}
