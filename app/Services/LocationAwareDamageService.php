<?php

namespace App\Services;

/**
 * Daños location-aware (PR #81). Fachada explícita sobre
 * LocationAwareStockDocumentService. Trabaja DENTRO de la transacción del
 * controller. Nunca escribe product_warehouse; nunca hace clamp a 0
 * (InventoryService rechaza negativos y consumo de reservado).
 */
class LocationAwareDamageService
{
    public function __construct(private LocationAwareStockDocumentService $engine)
    {
    }

    public function validateAndLock(int $warehouseId, ?int $locationId, array $lines, array $extraProductIds = []): array
    {
        return $this->engine->validateAndLock($warehouseId, $locationId, $lines, requireType: false, extraProductIds: $extraProductIds);
    }

    public function buildSnapshot(array $linesWithDetailIds): array
    {
        return $this->engine->buildDamageSnapshot($linesWithDetailIds);
    }

    public function normalizeSnapshot($raw): array
    {
        return $this->engine->normalizeSnapshot($raw);
    }

    public function applySnapshot(array $effects, int $damageId, int $warehouseId, int $locationId, string $source): void
    {
        $this->engine->applySnapshot($effects, LocationAwareStockDocumentService::REF_DAMAGE, $damageId, $warehouseId, $locationId, $source);
    }

    public function reverseSnapshot(array $effects, int $damageId, int $warehouseId, int $locationId, string $source): void
    {
        $this->engine->reverseSnapshot($effects, LocationAwareStockDocumentService::REF_DAMAGE_REVERSAL, $damageId, $warehouseId, $locationId, $source);
    }
}
