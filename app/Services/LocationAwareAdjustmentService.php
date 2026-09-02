<?php

namespace App\Services;

/**
 * Ajustes location-aware (PR #81). Fachada explícita sobre
 * LocationAwareStockDocumentService. Trabaja DENTRO de la transacción del
 * controller. Nunca escribe product_warehouse.
 */
class LocationAwareAdjustmentService
{
    public function __construct(private LocationAwareStockDocumentService $engine)
    {
    }

    /** Valida + bloquea DENTRO de la transacción del controller. */
    public function validateAndLock(int $warehouseId, ?int $locationId, array $lines, array $extraProductIds = []): array
    {
        return $this->engine->validateAndLock($warehouseId, $locationId, $lines, requireType: true, extraProductIds: $extraProductIds);
    }

    /** Efectos EXPANDIDOS del documento (componentes de combo incluidos). */
    public function buildSnapshot(array $linesWithDetailIds): array
    {
        return $this->engine->buildAdjustmentSnapshot($linesWithDetailIds);
    }

    public function normalizeSnapshot($raw): array
    {
        return $this->engine->normalizeSnapshot($raw);
    }

    /** (#81 · D5) FAIL CLOSED si algún producto del snapshot ahora es batch/IMEI. */
    public function assertSnapshotArtifactSafeAndLock(array $snapshot): void
    {
        $this->engine->assertSnapshotArtifactSafeAndLock($snapshot);
    }

    public function applySnapshot(array $effects, int $adjustmentId, int $warehouseId, int $locationId, string $source): void
    {
        $this->engine->applySnapshot($effects, LocationAwareStockDocumentService::REF_ADJUSTMENT, $adjustmentId, $warehouseId, $locationId, $source);
    }

    public function reverseSnapshot(array $effects, int $adjustmentId, int $warehouseId, int $locationId, string $source): void
    {
        $this->engine->reverseSnapshot($effects, LocationAwareStockDocumentService::REF_ADJUSTMENT_REVERSAL, $adjustmentId, $warehouseId, $locationId, $source);
    }
}
