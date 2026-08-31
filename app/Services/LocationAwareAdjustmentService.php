<?php

namespace App\Services;

/**
 * Ajustes location-aware (PR #81). Fachada explícita sobre
 * LocationAwareStockDocumentService. Trabaja DENTRO de la transacción del
 * controller. Nunca toca product_warehouse.
 *
 * @param  array<int,array{product_id:int,product_variant_id:?int,quantity:float,type:string,product_type?:string,detail_id?:int}>  $lines
 */
class LocationAwareAdjustmentService
{
    public function __construct(private LocationAwareStockDocumentService $engine)
    {
    }

    /** @return array{location: \App\Models\InventoryLocation, lines: array} */
    public function validateRequest(int $warehouseId, ?int $locationId, array $lines): array
    {
        return $this->engine->validateRequest($warehouseId, $locationId, $lines, requireType: true);
    }

    public function apply(int $adjustmentId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->engine->applyAdjustment($adjustmentId, $warehouseId, $locationId, $lines, $source);
    }

    public function reverse(int $adjustmentId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->engine->reverseAdjustment($adjustmentId, $warehouseId, $locationId, $lines, $source);
    }
}
