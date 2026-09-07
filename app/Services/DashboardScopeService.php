<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DashboardScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolución central del alcance analítico del Panel PRODEX.
 *
 * Un único lugar decide:
 *   - isOwner / managedBranchIds / operationalBranchIds / allowedBranchIds
 *   - selectedBranchId (validado SIEMPRE contra el alcance, nunca desde el cliente)
 *   - effectiveWarehouseIds
 *   - personalUserId
 *   - canSeeTeam / canSeeFinancialManagement
 *   - consolidatedView / hasBranch
 *
 * El {@see \App\Http\Controllers\DashboardController} consume el {@see DashboardScope}
 * resultante y NO vuelve a preguntar `if role == ...`, `if record_view ...` ni
 * `if manager ...` en cada consulta.
 *
 * `record_view` NO participa aquí: es una restricción de listados/reportes con
 * semántica global propia y el Panel dejó de usarla como palanca de granularidad.
 *
 * `is_all_warehouses` ("Toda la empresa") SÍ participa, pero SÓLO como ALCANCE
 * ORGANIZACIONAL: cuando un usuario no-owner y no-gerente lo tiene activo y no
 * tiene una selección explícita de sucursales (`user_branches`), su alcance son
 * TODAS las sucursales activas. NUNCA le concede autoridad de equipo ni
 * financiera — eso lo sigue decidiendo {@see authority()} a partir de
 * `manager_employee_id`. Alinea el Panel con {@see \App\Services\BranchScopeService}.
 */
class DashboardScopeService
{
    /**
     * Único marcador estructural de propietario en PRODEX. Encapsulado aquí para
     * que el Panel no dependa del literal `role_id === 1` disperso por los
     * controladores; si el mecanismo cambia, se cambia SOLO en este método.
     */
    public const OWNER_ROLE_ID = 1;

    public function resolve(User $user, ?int $requestedBranchId = null): DashboardScope
    {
        $isOwner = $this->isOwner($user);

        $managedBranchIds = $isOwner ? [] : $this->managedBranchIds($user);
        $explicitBranchIds = $isOwner ? [] : $this->explicitBranchIds($user);
        $operationalBranchIds = $isOwner ? [] : $this->operationalBranchIds($user);

        // "Toda la empresa": un usuario operativo (no gerente) con
        // `is_all_warehouses = 1` y SIN selección explícita de sucursales tiene
        // alcance organizacional sobre todas las sucursales activas. La autoridad
        // (equipo / financiera) NO cambia: la sigue gobernando authority().
        $isAllCompany = ! $isOwner
            && $managedBranchIds === []
            && $explicitBranchIds === []
            && (int) ($user->is_all_warehouses ?? 0) === 1;

        if ($isOwner || $isAllCompany) {
            $allowedBranchIds = $this->allActiveBranchIds();
        } elseif ($explicitBranchIds !== []) {
            // Selección explícita de sucursales: SÓLO esas (aunque tenga la
            // bandera "Toda la empresa"), más las que gestione.
            $allowedBranchIds = $this->activeUnique(array_merge($explicitBranchIds, $managedBranchIds));
        } else {
            $allowedBranchIds = $this->activeUnique(array_merge($managedBranchIds, $operationalBranchIds));
        }

        // El branch_id del cliente SIEMPRE se valida contra el alcance permitido.
        // Un id fuera de alcance (o manipulado) se ignora — nunca filtra datos.
        $selectedBranchId = 0;
        $requested = (int) ($requestedBranchId ?? 0);
        if ($requested > 0 && in_array($requested, $allowedBranchIds, true)) {
            $selectedBranchId = $requested;
        }

        // El owner siempre tiene alcance de negocio. Un usuario operativo sin
        // sucursal resoluble queda con alcance vacío: cero datos + estado suave.
        $hasBranch = $isOwner || $allowedBranchIds !== [];

        // Sucursal(es) del alcance ACTIVO: la selección concreta, o todas las
        // permitidas cuando no hay una elegida.
        $effectiveBranchIds = $selectedBranchId > 0 ? [$selectedBranchId] : $allowedBranchIds;

        $effectiveWarehouseIds = $this->effectiveWarehouseIds($isOwner, $selectedBranchId, $allowedBranchIds);

        // Sin sucursal concreta seleccionada. Para el owner: "todo el negocio";
        // para un gerente multi-sucursal: "todas mis sucursales".
        $consolidatedView = $selectedBranchId === 0;

        [$canSeeTeam, $canSeeFinancialManagement] = $this->authority(
            $isOwner,
            $managedBranchIds,
            $allowedBranchIds,
            $selectedBranchId
        );

        return new DashboardScope(
            isOwner: $isOwner,
            managedBranchIds: array_values($managedBranchIds),
            operationalBranchIds: array_values($this->activeUnique(array_merge($explicitBranchIds, $operationalBranchIds))),
            allowedBranchIds: array_values($allowedBranchIds),
            selectedBranchId: $selectedBranchId,
            effectiveBranchIds: array_values($effectiveBranchIds),
            effectiveWarehouseIds: array_values($effectiveWarehouseIds),
            personalUserId: (int) $user->id,
            canSeeTeam: $canSeeTeam,
            canSeeFinancialManagement: $canSeeFinancialManagement,
            consolidatedView: $consolidatedView,
            hasBranch: $hasBranch,
        );
    }

    /**
     * Único punto de detección del propietario para el Panel.
     */
    public function isOwner(User $user): bool
    {
        return (int) $user->role_id === self::OWNER_ROLE_ID;
    }

    /**
     * Sucursales que el usuario GESTIONA, vía `branches.manager_employee_id`
     * (Branch → Employee → User.employee_id). NO por nombre de rol.
     *
     * @return list<int>
     */
    private function managedBranchIds(User $user): array
    {
        $employeeId = (int) ($user->employee_id ?? 0);

        if ($employeeId <= 0
            || ! Schema::hasTable('branches')
            || ! Schema::hasColumn('branches', 'manager_employee_id')) {
            return [];
        }

        return Branch::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('manager_employee_id', $employeeId)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Selección EXPLÍCITA de sucursales del usuario (`user_branches`). Cuando
     * existe, acota el alcance a esas sucursales aunque el usuario tenga la
     * bandera "Toda la empresa".
     *
     * @return list<int>
     */
    private function explicitBranchIds(User $user): array
    {
        if (! Schema::hasTable('user_branches')) {
            return [];
        }

        return $this->activeUnique(
            DB::table('user_branches')
                ->where('user_id', $user->id)
                ->pluck('branch_id')
                ->map(static fn ($id) => (int) $id)
                ->all()
        );
    }

    /**
     * Sucursal(es) HABITUAL(ES) del usuario: sucursal por defecto, sucursal del
     * empleado y asignación temporal vigente. Son el "hogar" operativo, no una
     * restricción de alcance; `explicitBranchIds()` sí restringe.
     *
     * @return list<int>
     */
    private function operationalBranchIds(User $user): array
    {
        $ids = [];

        if ($user->default_branch_id) {
            $ids[] = (int) $user->default_branch_id;
        }

        if (optional($user->employee)->branch_id) {
            $ids[] = (int) $user->employee->branch_id;
        }

        $temporary = $this->activeTemporaryBranchId($user);
        if ($temporary !== null) {
            $ids[] = $temporary;
        }

        return $this->activeUnique($ids);
    }

    private function activeTemporaryBranchId(User $user): ?int
    {
        if (! Schema::hasTable('user_operational_assignments')
            || ! Schema::hasColumn('user_operational_assignments', 'temporary_branch_id')) {
            return null;
        }

        $now = Carbon::now();

        $row = DB::table('user_operational_assignments')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();

        return $row && $row->temporary_branch_id ? (int) $row->temporary_branch_id : null;
    }

    /**
     * Almacenes efectivos = almacenes de la(s) sucursal(es) en alcance.
     *
     * Nunca hay fallback a "todos los almacenes permitidos": un usuario
     * operativo sin sucursal resoluble obtiene `[]` (cero datos).
     *
     * @param  list<int>  $allowedBranchIds
     * @return list<int>
     */
    private function effectiveWarehouseIds(bool $isOwner, int $selectedBranchId, array $allowedBranchIds): array
    {
        if (! Schema::hasTable('warehouses')) {
            return [];
        }

        // Owner sin sucursal concreta => negocio completo, incluidos los
        // almacenes sin branch_id (compatibilidad histórica).
        if ($isOwner && $selectedBranchId === 0) {
            return Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $branchIds = $selectedBranchId > 0 ? [$selectedBranchId] : $allowedBranchIds;

        if ($branchIds === []) {
            return [];
        }

        return Warehouse::whereNull('deleted_at')
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Autoridad de EQUIPO (desglose por cajero, ventas recientes del equipo) y de
     * GESTIÓN FINANCIERA (compras, utilidad, valorización, pagos, etc.).
     *
     * - Owner: siempre.
     * - Gerente: sólo sobre la(s) sucursal(es) que gestiona. Al ver una sucursal
     *   concreta, debe gestionarla; en vista consolidada, debe gestionar TODAS
     *   las de su alcance (criterio de menor exposición).
     * - Cajero / operativo: nunca.
     *
     * Ambas banderas comparten fórmula en esta fase pero se exponen por separado
     * para poder divergir sin reescribir el controlador.
     *
     * @param  list<int>  $managedBranchIds
     * @param  list<int>  $allowedBranchIds
     * @return array{0: bool, 1: bool}
     */
    private function authority(bool $isOwner, array $managedBranchIds, array $allowedBranchIds, int $selectedBranchId): array
    {
        if ($isOwner) {
            return [true, true];
        }

        if ($managedBranchIds === []) {
            return [false, false];
        }

        if ($selectedBranchId > 0) {
            $manages = in_array($selectedBranchId, $managedBranchIds, true);

            return [$manages, $manages];
        }

        $coversEntireScope = $allowedBranchIds !== []
            && array_diff($allowedBranchIds, $managedBranchIds) === [];

        return [$coversEntireScope, $coversEntireScope];
    }

    /**
     * @return list<int>
     */
    private function allActiveBranchIds(): array
    {
        if (! Schema::hasTable('branches')) {
            return [];
        }

        return Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Normaliza a enteros únicos y re-filtra a sucursales activas no eliminadas.
     *
     * @param  array<int|string>  $branchIds
     * @return list<int>
     */
    private function activeUnique(array $branchIds): array
    {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));

        if ($branchIds === [] || ! Schema::hasTable('branches')) {
            return [];
        }

        return Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('id', $branchIds)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
