<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resultado inmutable de {@see \App\Services\DashboardScopeService::resolve()}.
 *
 * Reúne, en un único objeto, las DOS dimensiones independientes del alcance
 * analítico del Panel PRODEX:
 *
 *   A. ORGANIZATION SCOPE  — qué sucursales / almacenes puede consultar el
 *      usuario ({@see $allowedBranchIds}, {@see $selectedBranchId},
 *      {@see $effectiveWarehouseIds}, {@see $hasBranch}).
 *
 *   B. METRIC VISIBILITY   — con qué granularidad puede ver cada métrica
 *      ({@see $canSeeTeam}, {@see $canSeeFinancialManagement},
 *      {@see $personalUserId}).
 *
 * Ninguna de las dos usa `record_view`: la autoridad de un gerente proviene de
 * ser `branches.manager_employee_id`, no de un permiso de listados.
 */
final class DashboardScope
{
    /**
     * @param  list<int>  $managedBranchIds       Sucursales que el usuario gestiona (branches.manager_employee_id).
     * @param  list<int>  $operationalBranchIds   Sucursales donde el usuario opera (asignación / default / temporal).
     * @param  list<int>  $allowedBranchIds       Unión resuelta: alimenta el selector de sucursal.
     * @param  list<int>  $effectiveBranchIds     Sucursal(es) del alcance ACTIVO (selección concreta o todas las permitidas).
     * @param  list<int>  $effectiveWarehouseIds  Almacenes de la(s) sucursal(es) en alcance. Vacío = cero datos.
     */
    public function __construct(
        public readonly bool $isOwner,
        public readonly array $managedBranchIds,
        public readonly array $operationalBranchIds,
        public readonly array $allowedBranchIds,
        public readonly int $selectedBranchId,
        public readonly array $effectiveBranchIds,
        public readonly array $effectiveWarehouseIds,
        public readonly int $personalUserId,
        public readonly bool $canSeeTeam,
        public readonly bool $canSeeFinancialManagement,
        public readonly bool $consolidatedView,
        public readonly bool $hasBranch,
    ) {
    }

    /**
     * Lista de `branch_id` para un `whereIn`, nunca vacía para SQL.
     *
     * @return list<int>
     */
    public function branchFilterIds(): array
    {
        return $this->effectiveBranchIds !== [] ? $this->effectiveBranchIds : [0];
    }

    /**
     * Lista de almacenes lista para un `whereIn('warehouse_id', ...)`.
     * Nunca vacía (usa `[0]`) para evitar un `IN ()` inválido en SQL; `[0]`
     * jamás corresponde a un almacén real, así que el resultado es cero filas.
     *
     * @return list<int>
     */
    public function warehouseFilterIds(): array
    {
        return $this->effectiveWarehouseIds !== [] ? $this->effectiveWarehouseIds : [0];
    }

    /**
     * Restringe un `warehouse_id` solicitado por el cliente al alcance efectivo.
     * Un id fuera de alcance (o 0) devuelve la lista completa del alcance.
     *
     * @return list<int>
     */
    public function resolveWarehouseFilter(int $requestedWarehouseId): array
    {
        if ($requestedWarehouseId > 0 && in_array($requestedWarehouseId, $this->effectiveWarehouseIds, true)) {
            return [$requestedWarehouseId];
        }

        return $this->warehouseFilterIds();
    }

    /**
     * Contrato mínimo para el frontend px-next: gobierna el selector, el estado
     * "sin sucursal", el bloque financiero y la tabla de ventas por cajero.
     *
     * @return array<string, bool|int>
     */
    public function toClientArray(): array
    {
        return [
            'is_owner' => $this->isOwner,
            'selected_branch_id' => $this->selectedBranchId,
            'consolidated' => $this->consolidatedView,
            'has_branch' => $this->hasBranch,
            'can_see_team' => $this->canSeeTeam,
            'can_see_financial' => $this->canSeeFinancialManagement,
        ];
    }
}
