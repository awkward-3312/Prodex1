<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SAR / CAI fiscal invoicing for multiple branches.
 *
 *   Branch -> InventoryLocation -> CashDrawer -> SAR Point -> Authorization -> CAI -> range
 *
 * sar_points_of_issue is explicitly related to branch_id + inventory_location_id
 * + cash_drawer_id. warehouse_id stays for legacy compatibility only. A sale can
 * never consume the CAI / correlativo range of another branch.
 */
class SarMultiBranchFiscalArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_new_migration_adds_branch_and_location_and_backfills_only_from_cash_drawer(): void
    {
        $m = $this->read('database/migrations/tenant/2026_09_07_000000_link_sar_points_to_branch_location_drawer.php');

        $this->assertStringContainsString("\$table->unsignedInteger('branch_id')->nullable()", $m);
        $this->assertStringContainsString("\$table->unsignedInteger('inventory_location_id')->nullable()", $m);

        // Backfill source is CashDrawer, never Warehouse.
        $this->assertStringContainsString("->whereNotNull('cash_drawer_id')", $m);
        $this->assertStringContainsString("DB::table('cash_drawers')", $m);
        $this->assertStringNotContainsString("warehouses.branch_id", $m);
        $this->assertStringNotContainsString("->join('warehouses'", $m);

        // Ambiguous legacy points are left pending, not invented.
        $this->assertStringContainsString('leave pending configuration', $m);

        // Never writes to authorizations / documents / correlativos.
        $this->assertStringNotContainsString("table('sar_authorizations')", $m);
        $this->assertStringNotContainsString("table('sar_fiscal_documents')", $m);
        $this->assertStringNotContainsString('next_number', $m);
        $this->assertStringNotContainsString("update(['status'", $m);

        // down() only drops the two new columns.
        $this->assertStringContainsString("['inventory_location_id', 'branch_id']", $m);
    }

    public function test_migration_is_registered_for_controlled_tenant_upgrade(): void
    {
        $health = $this->read('app/Services/TenantSchemaHealthService.php');
        $this->assertStringContainsString(
            '2026_09_07_000000_link_sar_points_to_branch_location_drawer.php',
            $health
        );
    }

    public function test_model_relates_the_point_to_branch_location_and_drawer(): void
    {
        $model = $this->read('app/Models/SarPointOfIssue.php');

        $this->assertStringContainsString("'branch_id', 'inventory_location_id', 'cash_drawer_id'", $model);
        $this->assertStringContainsString('function branch()', $model);
        $this->assertStringContainsString('function inventoryLocation()', $model);
        $this->assertStringContainsString('function cashDrawer()', $model);
        $this->assertMatchesRegularExpression(
            '/scopeForOperationalContext\(\$query, int \$branchId, int \$inventoryLocationId, int \$cashDrawerId\).*?'
            .'->where\(\x27branch_id\x27, \$branchId\).*?'
            .'->where\(\x27inventory_location_id\x27, \$inventoryLocationId\).*?'
            .'->where\(\x27cash_drawer_id\x27, \$cashDrawerId\)/s',
            $model
        );
    }

    public function test_controller_validates_the_operational_chain_and_exposes_readiness(): void
    {
        $c = $this->read('app/Http/Controllers/SarFiscalSettingsController.php');

        // storePoint / updatePoint require the three ids.
        $this->assertStringContainsString("'branch_id' => ['required', 'integer', 'exists:branches,id']", $c);
        $this->assertStringContainsString("'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id']", $c);
        $this->assertStringContainsString("'cash_drawer_id' => ['required', 'integer', 'exists:cash_drawers,id']", $c);

        // The three must really belong to each other.
        $this->assertStringContainsString('La ubicación de inventario no pertenece a la sucursal seleccionada.', $c);
        $this->assertStringContainsString('La caja física no pertenece a la sucursal seleccionada.', $c);
        $this->assertStringContainsString('La caja física no opera desde la ubicación de inventario seleccionada.', $c);
        $this->assertStringContainsString('Ya existe otro punto SAR activo asignado a esta caja física.', $c);

        // has_active_cai == status active AND deadline >= today AND next_number in range.
        $this->assertMatchesRegularExpression(
            '/readyAuthorization\(SarPointOfIssue \$point\).*?'
            .'\$a->status !== \x27active\x27.*?'
            .'Carbon::parse\(\$a->deadline\)->lt\(\$today\).*?'
            .'\$next >= \(int\) \$a->range_start && \$next <= \(int\) \$a->range_end/s',
            $c
        );
        $this->assertStringContainsString("'has_active_cai'", $c);
        $this->assertStringContainsString("'fiscal_ready'", $c);
        $this->assertStringContainsString('fiscalGaps($points)', $c);
        $this->assertStringContainsString("'sin_punto_sar'", $c);
        $this->assertStringContainsString("'sin_cai_activo'", $c);
    }

    public function test_readiness_semantics_are_the_same_in_service_and_ui(): void
    {
        $service = $this->read('app/Services/SarFiscalSaleService.php');
        $vue = $this->read('resources/src/views/app/pages/settings/sar_fiscal.vue');

        // Service: findActiveAuthorization enforces the exact triple.
        $this->assertStringContainsString("->where('status', 'active')", $service);
        $this->assertStringContainsString("\$authorization->deadline->isBefore(today())", $service);
        $this->assertStringContainsString("\$next < (int) \$authorization->range_start || \$next > (int) \$authorization->range_end", $service);
        $this->assertStringContainsString("'expired'", $service);
        $this->assertStringContainsString("'exhausted'", $service);

        // UI mirrors it (status active + not past deadline + next within range).
        $this->assertMatchesRegularExpression(
            '/authIsReady\(point, auth\).*?auth\.status !== "active".*?'
            .'String\(auth\.deadline\)\.slice\(0, 10\) < this\.todayStr\(\).*?'
            .'next >= Number\(auth\.range_start\) && next <= Number\(auth\.range_end\)/s',
            $vue
        );
    }

    public function test_point_modal_order_and_dependent_selects(): void
    {
        $vue = $this->read('resources/src/views/app/pages/settings/sar_fiscal.vue');

        // Field order in the modal: Sucursal -> Ubicación -> Caja -> Establecimiento -> Punto -> Nombre -> Dirección -> Activo.
        $order = ['Sucursal *', 'Ubicación de inventario *', 'Caja física *', 'Código de establecimiento *',
            'Código del punto *', 'Nombre *', 'Dirección *', '>Activo<'];
        $last = -1;
        foreach ($order as $label) {
            $pos = strpos($vue, $label);
            $this->assertNotFalse($pos, "Falta el campo del modal: {$label}");
            $this->assertGreaterThan($last, $pos, "El campo '{$label}' está fuera de orden en el modal.");
            $last = $pos;
        }

        // Dependent selects: branch filters locations, location filters drawers.
        $this->assertStringContainsString('@input="onPointBranchChange"', $vue);
        $this->assertStringContainsString('@input="onPointLocationChange"', $vue);
        $this->assertMatchesRegularExpression(
            '/onPointBranchChange\(value\)\s*\{\s*this\.pointForm\.branch_id = value;\s*'
            .'this\.pointForm\.inventory_location_id = null;\s*this\.pointForm\.cash_drawer_id = null;/s',
            $vue
        );
        $this->assertMatchesRegularExpression(
            '/onPointLocationChange\(value\)\s*\{\s*this\.pointForm\.inventory_location_id = value;\s*'
            .'this\.pointForm\.cash_drawer_id = null;/s',
            $vue
        );
        $this->assertStringContainsString('pointLocationOptions()', $vue);
        $this->assertStringContainsString('pointDrawerOptions()', $vue);

        // Points table columns.
        foreach (['<th>Sucursal</th>', '<th>Establecimiento</th>', '<th>Punto</th>', '<th>Ubicación</th>',
            '<th>Caja</th>', '<th>CAI activo</th>', '<th>Estado</th>'] as $th) {
            $this->assertStringContainsString($th, $vue, "Falta la columna {$th}");
        }
        $this->assertStringContainsString('fiscalGaps.length', $vue);
    }

    public function test_no_data_loss_paths_are_touched(): void
    {
        $base = dirname(__DIR__, 2);
        // Historical documents render from immutable snapshots — untouched.
        $reprint = file_get_contents($base.'/app/Http/Controllers/SarDirectNetworkPrintController.php');
        $this->assertStringContainsString('issuer_snapshot', $reprint);

        // The correlative allocation transaction / lock is unchanged.
        $number = file_get_contents($base.'/app/Services/SarFiscalNumberService.php');
        $this->assertStringContainsString('->lockForUpdate()', $number);
        $this->assertStringContainsString("\$authorization->next_number = \$sequence + 1;", $number);
    }
}
