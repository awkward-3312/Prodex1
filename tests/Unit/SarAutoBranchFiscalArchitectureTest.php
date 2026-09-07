<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PRODEX auto-manages the SAR technical structure per branch.
 *
 *   - "Facturación SAR habilitada" lives on sar_branch_settings (per branch).
 *   - One PRODEX-managed sar_points_of_issue per branch (is_auto_managed).
 *   - A point covers many cash drawers through sar_point_cash_drawers; a drawer
 *     maps to at most one point (cash_drawer_id is UNIQUE).
 *   - PRODEX never invents SAR-authorised data: establishment_code / point_code /
 *     CAI / range / deadline stay tenant-provided and are only widened to
 *     nullable so a managed point can exist before the admin types them.
 *   - Existing manual points and issued documents are untouched.
 */
class SarAutoBranchFiscalArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_migration_adds_per_branch_schema_without_touching_fiscal_history(): void
    {
        $m = $this->read('database/migrations/tenant/2026_09_08_000000_sar_per_branch_fiscal_config.php');

        $this->assertStringContainsString("Schema::create('sar_branch_settings'", $m);
        $this->assertStringContainsString("Schema::create('sar_point_cash_drawers'", $m);
        $this->assertStringContainsString("\$table->unsignedInteger('cash_drawer_id')->unique()", $m);
        $this->assertStringContainsString("\$table->boolean('is_auto_managed')->default(false)", $m);

        // establishment_code / point_code are only WIDENED to nullable.
        $this->assertStringContainsString('makeNullable', $m);
        $this->assertStringContainsString("'establishment_code'", $m);
        $this->assertStringContainsString("'point_code'", $m);

        // Idempotent backfill: existing single-drawer point -> pivot row.
        $this->assertStringContainsString("->whereNotNull('cash_drawer_id')", $m);
        $this->assertStringContainsString("DB::table('sar_point_cash_drawers')->insert(", $m);

        // Nothing fiscal is rewritten.
        $this->assertStringNotContainsString("table('sar_authorizations')->update", $m);
        $this->assertStringNotContainsString("table('sar_fiscal_documents')", $m);
        $this->assertStringNotContainsString("'next_number'", $m);
        $this->assertStringNotContainsString('->truncate(', $m);
        $this->assertStringNotContainsString('dropColumn(\'cash_drawer_id\')', $m);
    }

    public function test_migration_is_registered_for_controlled_tenant_upgrade(): void
    {
        $health = $this->read('app/Services/TenantSchemaHealthService.php');
        $this->assertStringContainsString(
            '2026_09_08_000000_sar_per_branch_fiscal_config.php',
            $health
        );
    }

    public function test_service_manages_one_point_per_branch_and_never_invents_codes(): void
    {
        $s = $this->read('app/Services/SarBranchFiscalService.php');

        $this->assertStringContainsString('function syncAllActiveBranches(): void', $s);
        $this->assertStringContainsString('function setBranchEnabled(int $branchId, bool $enabled)', $s);
        $this->assertStringContainsString('function syncBranch(Branch $branch)', $s);
        $this->assertStringContainsString('function syncCashDrawer(CashDrawer $drawer): void', $s);

        // Every active branch is registered; branches start disabled.
        $this->assertStringContainsString("where('is_active', true)", $s);
        $this->assertStringContainsString("firstOrCreate(", $s);
        $this->assertStringContainsString("['enabled' => false]", $s);

        // The managed point is created with BLANK codes — PRODEX never fills them.
        $this->assertStringContainsString("'establishment_code' => null", $s);
        $this->assertStringContainsString("'point_code' => null", $s);
        $this->assertStringContainsString("'is_auto_managed' => true", $s);

        // An existing single manual point is adopted, not duplicated.
        $this->assertStringContainsString("where('is_auto_managed', false)", $s);
        $this->assertStringContainsString('$point->is_auto_managed = true;', $s);

        // "active" only once the tenant's codes are present and a drawer is covered.
        $this->assertStringContainsString('$point->hasCodes() && $covers > 0', $s);

        // A drawer already claimed by another point is left alone (no stealing).
        $this->assertStringContainsString('$claimedElsewhere', $s);
    }

    public function test_pos_resolution_is_branch_scoped_and_gated_by_the_enable_flag(): void
    {
        $r = $this->read('app/Services/PosAwareSarFiscalSaleService.php');

        $this->assertStringContainsString("SarBranchSetting::where('branch_id', \$sale->branch_id)", $r);
        $this->assertStringContainsString('La facturación SAR no está habilitada para ', $r);
        $this->assertStringContainsString('->coveringDrawer($cashDrawerId)', $r);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $r);

        // Cross-branch drawer / point / authorization are all rejected.
        $this->assertStringContainsString('(int) $drawer->branch_id !== (int) $sale->branch_id', $r);
        $this->assertStringContainsString('(int) $point->branch_id !== (int) $sale->branch_id', $r);
        $this->assertStringContainsString('(int) $authorization->pointOfIssue->branch_id !== (int) $sale->branch_id', $r);

        // warehouse_id is never the source of truth here.
        $this->assertStringNotContainsString("setAttribute('warehouse_id'", $r);
    }

    public function test_model_exposes_the_pivot_and_a_drawer_scoped_lookup(): void
    {
        $model = $this->read('app/Models/SarPointOfIssue.php');

        $this->assertStringContainsString('function cashDrawers()', $model);
        $this->assertStringContainsString("'sar_point_cash_drawers'", $model);
        $this->assertMatchesRegularExpression(
            '/scopeCoveringDrawer\(\$query, int \$cashDrawerId\).*?'
            ."->where\('active', true\).*?"
            ."whereHas\('cashDrawers'/s",
            $model
        );
        $this->assertStringContainsString('function hasCodes(): bool', $model);
        // Legacy scope kept for pre-pivot points.
        $this->assertStringContainsString('function scopeForOperationalContext', $model);
    }

    public function test_a_new_cash_drawer_is_synced_from_the_controller(): void
    {
        $c = $this->read('app/Http/Controllers/CashDrawerController.php');

        $this->assertStringContainsString('SarBranchFiscalService', $c);
        $this->assertStringContainsString('syncCashDrawer', $c);
        // Best-effort: a fiscal-sync failure must not block the drawer save.
        $this->assertMatchesRegularExpression('/try\s*\{\s*app\(SarBranchFiscalService::class\)->syncCashDrawer/s', $c);
    }

    public function test_authorization_can_be_registered_per_branch(): void
    {
        $c = $this->read('app/Http/Controllers/SarFiscalSettingsController.php');

        // storeAuthorization accepts branch_id and maps it to the managed point.
        $this->assertStringContainsString("'branch_id' => ['nullable', 'integer', 'exists:branches,id']", $c);
        $this->assertStringContainsString('app(SarBranchFiscalService::class)->syncBranch($branchModel)', $c);
        $this->assertStringContainsString("\$data['point_of_issue_id'] = \$point->id", $c);
    }
}
