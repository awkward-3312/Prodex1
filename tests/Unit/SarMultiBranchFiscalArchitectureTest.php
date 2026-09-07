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

        // Readiness ("CAI listo") is the single contract SarAuthorization::isUsableNow:
        //   status active/prepared AND deadline >= today AND next_number in range.
        $model = $this->read('app/Models/SarAuthorization.php');
        $this->assertMatchesRegularExpression(
            '/function isUsableNow.*?'
            ."in_array\(\\\$this->status, \['active', 'prepared'\], true\).*?"
            .'Carbon::parse\(\$this->deadline\)->lt\(\$today\).*?'
            .'\$next >= \(int\) \$this->range_start && \$next <= \(int\) \$this->range_end/s',
            $model
        );
        $this->assertStringContainsString('$a->status === \'active\' && $a->isUsableNow()', $c);
        $this->assertStringContainsString("'has_active_cai'", $c);
        $this->assertStringContainsString("'fiscal_ready'", $c);
        $this->assertStringContainsString('fiscalGaps($points)', $c);
        $this->assertStringContainsString("'sin_punto_sar'", $c);
        $this->assertStringContainsString("'sin_cai_activo'", $c);
    }

    public function test_readiness_semantics_are_the_same_in_service_and_ui(): void
    {
        $model = $this->read('app/Models/SarAuthorization.php');
        $vue = $this->read('resources/src/views/app/pages/settings/sar_fiscal.vue');

        // The single source of truth for "usable now" is SarAuthorization.
        $this->assertMatchesRegularExpression(
            '/function isUsableNow.*?'
            ."in_array\(\\\$this->status, \['active', 'prepared'\], true\).*?"
            .'Carbon::parse\(\$this->deadline\)->lt\(\$today\).*?'
            .'next >= \(int\) \$this->range_start && \$next <= \(int\) \$this->range_end/s',
            $model
        );
        $this->assertStringContainsString("'expired'", $model);
        $this->assertStringContainsString("'exhausted'", $model);

        // The UI consumes the server-computed health / readiness, never re-derives.
        $this->assertStringContainsString('card.series.current.is_ready', $vue);
        $this->assertStringContainsString('card.series.health', $vue);
    }

    public function test_screen_is_fiscal_series_centric(): void
    {
        $vue = $this->read('resources/src/views/app/pages/settings/sar_fiscal.vue');

        // The tenant no longer creates points by hand: no manual modal, no
        // dependent-select plumbing, no "technical point" vocabulary.
        $this->assertStringNotContainsString('Agregar punto de emisión', $vue);
        $this->assertStringNotContainsString('onPointBranchChange', $vue);
        $this->assertStringNotContainsString('pointDrawerOptions', $vue);
        $this->assertStringNotContainsString('sar_points_of_issue', $vue);

        // The card is a fiscal series with its authorisation and coverage.
        $this->assertStringContainsString('v-for="card in branchCards"', $vue);
        $this->assertStringContainsString('serie_label', $vue);
        $this->assertStringContainsString('Facturación SAR habilitada', $vue);
        $this->assertStringContainsString('toggleBranch(card', $vue);
        $this->assertStringContainsString('saveBranchCodes(card)', $vue);
        $this->assertStringContainsString('toggleDrawer(card', $vue);
        $this->assertStringContainsString('saveBranchDrawers(card)', $vue);
        $this->assertStringContainsString("openAuthorization(card, 'current')", $vue);

        // Every field the brief lists for a series card.
        foreach (['CAI', 'Rango autorizado', 'Último utilizado', 'Siguiente correlativo', 'Disponibles', 'Fecha límite'] as $label) {
            $this->assertStringContainsString($label, $vue, "Falta el campo de serie: {$label}");
        }

        // "Siguiente autorización" (prepared) block.
        $this->assertStringContainsString('Siguiente autorización', $vue);
        $this->assertStringContainsString("openAuthorization(card, 'next')", $vue);
        $this->assertStringContainsString("card.series.next", $vue);

        $this->assertStringContainsString('readyCount', $vue);
        $this->assertStringContainsString('fiscalGaps.length', $vue);
    }

    public function test_per_branch_endpoints_and_service_are_wired(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString("sar-fiscal/branches/{branch}/toggle', 'SarFiscalSettingsController@toggleBranch'", $routes);
        $this->assertStringContainsString("sar-fiscal/branches/{branch}/point', 'SarFiscalSettingsController@saveBranchPoint'", $routes);
        $this->assertStringContainsString("sar-fiscal/branches/{branch}/drawers', 'SarFiscalSettingsController@saveBranchDrawers'", $routes);

        $c = $this->read('app/Http/Controllers/SarFiscalSettingsController.php');
        $this->assertStringContainsString('function branchCards(): array', $c);
        $this->assertStringContainsString('SarBranchFiscalService', $c);
        $this->assertStringContainsString("'branch_cards' => \$this->branchCards()", $c);

        // A new drawer is picked up automatically.
        $drawerCtl = $this->read('app/Http/Controllers/CashDrawerController.php');
        $this->assertStringContainsString('syncCashDrawer', $drawerCtl);

        // The per-branch schema is a controlled tenant migration.
        $health = $this->read('app/Services/TenantSchemaHealthService.php');
        $this->assertStringContainsString('2026_09_08_000000_sar_per_branch_fiscal_config.php', $health);
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
