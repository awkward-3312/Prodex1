<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cotización -> Venta POS traceability (Option A).
 *
 * A sale created by processing a quotation through the POS
 * (Cotización -> "Procesar en POS" -> PosController@CreatePOS) is permanently
 * linked to its source via sales.quotation_id (nullable, UNIQUE, no hard FK).
 *
 * Source-level architecture assertions (no DB / no app bootstrap), matching
 * the *ArchitectureTest.php convention already used in this suite.
 */
class QuotationSaleTraceabilityArchitectureTest extends TestCase
{
    private function read(string $path): string
    {
        return file_get_contents(\dirname(__DIR__, 2).'/'.$path);
    }

    // ------------------------------------------------------------------
    // 1. Migration
    // ------------------------------------------------------------------

    public function test_migration_adds_nullable_unique_quotation_id_without_hard_fk(): void
    {
        $m = $this->read('database/migrations/tenant/2026_09_05_000000_add_quotation_id_to_sales.php');

        $this->assertStringContainsString("hasColumn('sales', 'quotation_id')", $m);
        $this->assertStringContainsString("unsignedInteger('quotation_id')->nullable()", $m);
        $this->assertStringContainsString("unique('quotation_id', 'sales_quotation_id_unique')", $m);
        // Additive, reversible, and NOT a hard foreign key.
        $this->assertStringContainsString('public function down()', $m);
        $this->assertStringContainsString("dropUnique('sales_quotation_id_unique')", $m);
        $this->assertStringNotContainsString('->foreign(', $m);
        $this->assertStringNotContainsString('constrained(', $m);
    }

    public function test_migration_is_wired_into_tenant_upgrade_and_health(): void
    {
        $health = $this->read('app/Services/TenantSchemaHealthService.php');

        // Runs via prodex:tenant-upgrade (which merges CONTROLLED_MIGRATIONS).
        $this->assertStringContainsString(
            "database/migrations/tenant/2026_09_05_000000_add_quotation_id_to_sales.php",
            $health
        );
        // Reported as a schema requirement.
        $this->assertStringContainsString("requireColumns(\$schema, \$missing, 'sales', ['quotation_id'])", $health);
    }

    // ------------------------------------------------------------------
    // 2. Models
    // ------------------------------------------------------------------

    public function test_models_expose_the_relation_both_ways(): void
    {
        $sale = $this->read('app/Models/Sale.php');
        $this->assertStringContainsString("'quotation_id',", $sale);
        $this->assertStringContainsString("'quotation_id' => 'integer',", $sale);
        $this->assertStringContainsString("public function quotation() { return \$this->belongsTo('App\\Models\\Quotation', 'quotation_id'); }", $sale);

        $quotation = $this->read('app/Models/Quotation.php');
        $this->assertStringContainsString("public function sale()", $quotation);
        $this->assertStringContainsString("hasOne('App\\Models\\Sale', 'quotation_id')", $quotation);
    }

    // ------------------------------------------------------------------
    // 3. CreatePOS — server-side validation + single creation path
    // ------------------------------------------------------------------

    public function test_createpos_validates_the_quotation_server_side(): void
    {
        $pos = $this->read('app/Http/Controllers/PosController.php');

        $start = strpos($pos, 'public function CreatePOS(');
        $this->assertNotFalse($start);
        $end = strpos($pos, "\n    }\n", $start);
        $method = substr($pos, $start, $end - $start);

        // Never trust an arbitrary frontend quotation_id.
        $this->assertStringContainsString("\$request->filled('quotation_id')", $method);
        $this->assertStringContainsString('QUOTATION_NOT_FOUND', $method);
        $this->assertStringContainsString('QUOTATION_FORBIDDEN', $method);
        $this->assertStringContainsString('QUOTATION_ALREADY_CONVERTED', $method);
        // Warehouse restriction mirrors the prefill / Elemens_Change_To_Sale.
        $this->assertStringContainsString("UserWarehouse::where('user_id', \$__userAuth->id)", $method);
        // Persisted inside the SAME creation transaction, no second code path.
        $this->assertStringContainsString('use ($request, $totalPaid, $saleUuid, $promotionDiscount, $promotionCodeApplied, $appliedPromotions, $__sourceQuotationId)', $method);
        $this->assertStringContainsString('$order->quotation_id = $__sourceQuotationId;', $method);
        // Race: the unique index is the last-resort guard.
        $this->assertStringContainsString("str_contains(\$e->getMessage(), 'sales_quotation_id_unique')", $method);
        // The normal POS invariants are untouched.
        $this->assertStringContainsString('$order->is_pos = 1;', $method);
        $this->assertStringContainsString('$order->user_id = Auth::user()->id;', $method);
        $this->assertStringContainsString('$order->date = Carbon::now();', $method);
    }

    public function test_prefill_endpoint_stays_read_only(): void
    {
        $pos = $this->read('app/Http/Controllers/PosController.php');

        $start = strpos($pos, 'public function data_quotation_prefill(');
        $end = strpos($pos, 'public function GetProductsByParametre(', $start);
        $method = substr($pos, $start, $end - $start);

        $this->assertStringNotContainsString('new Sale', $method);
        $this->assertStringNotContainsString('new DraftSale', $method);
        $this->assertStringNotContainsString('PaymentSale::', $method);
        $this->assertStringNotContainsString('->save()', $method);
        $this->assertStringNotContainsString('PromotionUsage::', $method);
        // It only *reports* whether a link already exists.
        $this->assertStringContainsString("'already_converted'", $method);
    }

    // ------------------------------------------------------------------
    // 4. Cross-links (API + UI)
    // ------------------------------------------------------------------

    public function test_detail_endpoints_expose_the_link(): void
    {
        $quotationsCtrl = $this->read('app/Http/Controllers/QuotationsController.php');
        $this->assertStringContainsString("\$quote['linked_sale']", $quotationsCtrl);
        $this->assertStringContainsString("->where('quotation_id', \$quotation_data->id)", $quotationsCtrl);

        $salesCtrl = $this->read('app/Http/Controllers/SalesController.php');
        $this->assertStringContainsString("\$sale_details['quotation_id'] = \$sale_data->quotation_id;", $salesCtrl);
        $this->assertStringContainsString("\$sale_details['quotation_ref']", $salesCtrl);
    }

    public function test_quotation_detail_ui_swaps_process_button_for_generated_sale(): void
    {
        $detail = $this->read('resources/src/views/app/pages/quotations/detail_quotation.vue');

        $this->assertStringContainsString('v-if="!isLoading && quote.linked_sale"', $detail);
        $this->assertStringContainsString('$router.push(\'/app/sales/detail/\' + quote.linked_sale.id)' , $detail);
        $this->assertStringContainsString("\$t('Sale_generated')", $detail);
        // "Procesar en POS" only when NOT already converted.
        $this->assertStringContainsString('v-else-if="!isLoading && quote.statut', $detail);
    }

    public function test_sale_detail_ui_shows_source_quotation(): void
    {
        $detail = $this->read('resources/src/views/app/pages/sales/detail_sale.vue');

        $this->assertStringContainsString('#meta', $detail);
        $this->assertStringContainsString('sale.quotation_id', $detail);
        $this->assertStringContainsString('$router.push(\'/app/quotations/detail/\' + sale.quotation_id)' , $detail);
    }

    public function test_pos_sends_quotation_id_only_when_cart_came_from_a_quotation(): void
    {
        $pos = $this->read('resources/src/views/app/pages/pos.vue');

        // Tracked from the prefill, cleared on reset, forwarded to CreatePOS.
        $this->assertStringContainsString('this.sourceQuotationId = data.quotation_id || id;', $pos);
        $this->assertStringContainsString('this.sourceQuotationId = null;', $pos);
        $this->assertStringContainsString('quotation_id: this.sourceQuotationId || undefined,', $pos);

        $modal = $this->read('resources/src/views/app/components/ModernPaymentModal.vue');
        $this->assertStringContainsString('sourceQuotationId:', $modal);
        $this->assertStringContainsString('quotation_id: this.sourceQuotationId || undefined,', $modal);
    }
}
