<?php

namespace App\Services;

/**
 * Daños location-aware (PR #81). Fachada explícita sobre
 * LocationAwareStockDocumentService. Trabaja DENTRO de la transacción del
 * controller. Nunca toca product_warehouse; nunca hace clamp a 0 (InventoryService
 * rechaza negativos y consumo de reservado).
 *
 * @param  array<int,array{product_id:int,product_variant_id:?int,quantity:float,product_type?:string,detail_id?:int}>  $lines
 */
class LocationAwareDamageService
{
    public function __construct(private LocationAwareStockDocumentService $engine)
    {
    }

    /** @return array{location: \App\Models\InventoryLocation, lines: array} */
    public function validateRequest(int $warehouseId, ?int $locationId, array $lines): array
    {
        return $this->engine->validateRequest($warehouseId, $locationId, $lines, requireType: false);
    }

    public function apply(int $damageId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->engine->applyDamage($damageId, $warehouseId, $locationId, $lines, $source);
    }

    public function reverse(int $damageId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->engine->reverseDamage($damageId, $warehouseId, $locationId, $lines, $source);
    }
}
