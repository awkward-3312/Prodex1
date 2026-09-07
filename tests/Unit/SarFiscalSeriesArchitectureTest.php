<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The fiscal correlativo is owned by the AUTHORISATION of a fiscal series.
 *
 *   - one series (sar_points_of_issue) can cover many cash drawers (pivot)
 *   - each authorisation (sar_authorizations) carries its own next_number
 *   - a series may hold one `active` and one `prepared` "next" authorisation;
 *     PRODEX switches to the prepared one automatically and atomically
 *   - the number is assigned only at sale confirmation, under a lock scoped to
 *     that one series' authorisation rows — never the branch, never the profile,
 *     never the sales table
 */
class SarFiscalSeriesArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_migration_adds_prepared_state_and_transition_audit_only(): void
    {
        $m = $this->read('database/migrations/tenant/2026_09_09_000000_sar_fiscal_series_authorizations.php');

        // Widen the status enum to include `prepared`.
        $this->assertStringContainsString("ENUM('draft','active','prepared','exhausted','expired','disabled')", $m);

        // Transition audit columns.
        $this->assertStringContainsString("Schema::hasColumn('sar_authorizations', 'activated_at')", $m);
        $this->assertStringContainsString("Schema::hasColumn('sar_authorizations', 'exhausted_at')", $m);
        $this->assertStringContainsString("Schema::hasColumn('sar_authorizations', 'superseded_by_id')", $m);

        // Resolution index used by the number service lock.
        $this->assertStringContainsString("['point_of_issue_id', 'document_type', 'status']", $m);

        // Nothing about issued documents / correlativos is rewritten.
        $this->assertStringNotContainsString("Schema::table('sar_fiscal_documents'", $m);
        $this->assertStringNotContainsString("Schema::create('sar_fiscal_documents'", $m);
        $this->assertStringNotContainsString("update(['next_number'", $m);
        $this->assertStringNotContainsString('->truncate(', $m);
    }

    public function test_migration_is_registered_for_controlled_tenant_upgrade(): void
    {
        $health = $this->read('app/Services/TenantSchemaHealthService.php');
        $this->assertStringContainsString('2026_09_09_000000_sar_fiscal_series_authorizations.php', $health);
    }

    public function test_authorization_model_owns_the_readiness_and_health_contract(): void
    {
        $model = $this->read('app/Models/SarAuthorization.php');

        $this->assertStringContainsString('function isUsableNow', $model);
        $this->assertStringContainsString('function remaining(): int', $model);
        $this->assertStringContainsString('function healthState(', $model);
        $this->assertStringContainsString('function reconcileTerminalStatuses(): void', $model);
        $this->assertStringContainsString('function scopeForSeries($query, int $pointOfIssueId, string $documentType)', $model);
        $this->assertStringContainsString("'activated_at', 'exhausted_at', 'superseded_by_id'", $model);

        // Health states the fiscal-series screen needs.
        foreach (["'ready'", "'expiring'", "'running_low'", "'exhausted'", "'expired'"] as $state) {
            $this->assertStringContainsString($state, $model, "healthState must return {$state}");
        }
    }

    public function test_number_service_locks_only_the_series_counter_and_auto_transitions(): void
    {
        $s = $this->read('app/Services/SarFiscalNumberService.php');

        // New signature: the caller passes the SERIES, not a pre-resolved auth.
        $this->assertStringContainsString('function issue(', $s);
        $this->assertStringContainsString('int $pointOfIssueId', $s);
        $this->assertStringContainsString('string $documentType', $s);

        // The one and only lock: this series' authorisation rows, ordered by id.
        $this->assertMatchesRegularExpression(
            '/forSeries\(\$pointOfIssueId, \$documentType\)\s*->whereIn\([^)]*\)\s*->orderBy\(\x27id\x27\)\s*->lockForUpdate\(\)/s',
            $s
        );
        $this->assertStringNotContainsString('SarFiscalProfile::query()->lockForUpdate()', $s);
        $this->assertStringNotContainsString("where('sale_id', \$sale->id)->lockForUpdate()", $s);

        // Safe automatic transition to the prepared "next" authorisation.
        $this->assertMatchesRegularExpression(
            "/\\\$prepared->isUsableNow\(\).*?\\\$prepared->status = 'active';.*?\\\$prepared->activated_at = now\(\);/s",
            $s
        );
        $this->assertStringContainsString('superseded_by_id', $s);

        // Range exhaustion is recorded, not just thrown.
        $this->assertStringContainsString("\$authorization->status = 'exhausted';", $s);
        $this->assertStringContainsString('$authorization->exhausted_at = now();', $s);

        // Idempotency: a retried sale re-uses its document; a concurrent insert
        // race is caught on the unique index, not with a gap lock.
        $this->assertStringContainsString('UniqueConstraintViolationException', $s);
        $this->assertStringContainsString("SarFiscalDocument::where('sale_id', \$sale->id)->first()", $s);

        // The cross-series / cross-branch guard sits at the allocation point.
        $this->assertStringContainsString('$series->branch_id', $s);
        $this->assertStringContainsString('no puede consumir el CAI de una sucursal distinta', $s);
    }

    public function test_resolvers_resolve_a_series_not_a_pre_locked_authorization(): void
    {
        $legacy = $this->read('app/Services/SarFiscalSaleService.php');
        $modern = $this->read('app/Services/PosAwareSarFiscalSaleService.php');

        $this->assertStringContainsString('function issueForSeries(Sale $sale, SarPointOfIssue $series, string $documentType', $legacy);
        $this->assertStringContainsString('->issue(', $legacy);
        $this->assertStringContainsString('$series->id', $legacy);
        $this->assertStringContainsString('$documentType', $legacy);
        $this->assertStringNotContainsString('function findActiveAuthorization', $legacy);

        $this->assertStringContainsString('resolveSeriesForDrawer(', $modern);
        $this->assertStringContainsString('assertSeriesHasAuthorization($series)', $modern);
        $this->assertStringContainsString('issueForSeries($sale, $series, self::DOCUMENT_TYPE, $cashDrawerId)', $modern);
    }

    public function test_controller_supports_current_and_prepared_next_authorizations(): void
    {
        $c = $this->read('app/Http/Controllers/SarFiscalSettingsController.php');

        // storeAuthorization: role current|next; a "next" one is `prepared` and
        // must start after the active range.
        $this->assertStringContainsString("'role' => ['nullable', Rule::in(['current', 'next'])]", $c);
        $this->assertStringContainsString("\$data['status'] = 'prepared';", $c);
        $this->assertStringContainsString('debe empezar después del rango actual', $c);
        $this->assertStringContainsString("\$data['next_number'] = \$data['range_start'];", $c);

        // destroyAuthorization: only a not-yet-used draft / prepared one.
        $this->assertStringContainsString('function destroyAuthorization(', $c);
        $this->assertStringContainsString("in_array(\$authorization->status, ['draft', 'prepared'], true)", $c);
        $this->assertStringContainsString('$authorization->documents()->exists()', $c);

        // seriesPayload feeds the fiscal-series card.
        $this->assertStringContainsString('function seriesPayload(', $c);
        $this->assertStringContainsString("'serie_label'", $c);
        $this->assertStringContainsString("'last_used'", $c);
        $this->assertStringContainsString("'next' => \$shape(\$next)", $c);
        $this->assertStringContainsString('SarAuthorization::reconcileTerminalStatuses();', $c);
    }

    public function test_screen_shows_the_series_and_the_next_authorization(): void
    {
        $vue = $this->read('resources/src/views/app/pages/settings/sar_fiscal.vue');

        $this->assertStringNotContainsString('punto de emisión SAR a mano', $vue);
        $this->assertStringContainsString('card.serie_label', $vue);
        $this->assertStringContainsString('Autorización activa', $vue);
        $this->assertStringContainsString('Siguiente autorización', $vue);
        $this->assertStringContainsString('card.series.next', $vue);
        $this->assertStringContainsString("openAuthorization(card, 'next')", $vue);
        $this->assertStringContainsString('Preparada', $vue);
        // Every field the brief lists.
        foreach (['CAI', 'Rango autorizado', 'Último utilizado', 'Siguiente correlativo', 'Disponibles', 'Fecha límite'] as $label) {
            $this->assertStringContainsString($label, $vue);
        }
    }

    public function test_issued_documents_and_void_flow_are_untouched(): void
    {
        $number = $this->read('app/Services/SarFiscalNumberService.php');

        // Snapshots are still frozen into the document at issue time.
        $this->assertStringContainsString("'issuer_snapshot' =>", $number);
        $this->assertStringContainsString("'customer_snapshot' => \$customerSnapshot", $number);
        $this->assertStringContainsString("'sale_snapshot' => \$saleSnapshot", $number);

        // void() still just flips status + reason, nothing about correlativos.
        $this->assertMatchesRegularExpression(
            "/function void\(.*?'status' => 'voided'.*?'void_reason' => \\\$reason/s",
            $number
        );

        $reprint = $this->read('app/Http/Controllers/SarDirectNetworkPrintController.php');
        $this->assertStringContainsString('issuer_snapshot', $reprint);
    }
}
