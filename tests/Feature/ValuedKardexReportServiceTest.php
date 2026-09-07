<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reports\ValuedKardexReportService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryMovementReportSchema;
use Tests\TestCase;

/**
 * Kardex valorizado — servicio. Alcance branch-first / legacy-fallback,
 * réplica cronológica con WAC, reconciliación de UNIDADES, calidad de
 * valorización separada de la calidad de cantidad, traslado value-neutral.
 */
class ValuedKardexReportServiceTest extends TestCase
{
    use InventoryMovementReportSchema;

    private ValuedKardexReportService $svc;

    private User $owner;

    private int $b1;

    private int $b2;

    private int $wh1;

    private int $wh2;

    private int $prod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventoryReportSchema();
        $this->svc = app(ValuedKardexReportService::class);
        $this->owner = $this->owner();

        $this->b1 = $this->branch('Casa Matriz');
        $this->b2 = $this->branch('Sucursal Norte');
        $this->wh1 = $this->warehouse('WH1', $this->b1);
        $this->wh2 = $this->warehouse('WH2', $this->b2);

        $this->prod = $this->product('Tornillo', 1.00); // products.cost = 1.00 (NO debe usarse)
        $this->onHand($this->prod, $this->wh1, 100);
        $this->onHand($this->prod, $this->wh2, 50);

        // WH1 (sucursal b1) — historia documental legacy.
        $this->purchase($this->wh1, '2026-01-05', [['product_id' => $this->prod, 'cost' => 2.00, 'qty' => 60]]);
        $this->purchase($this->wh1, '2026-01-08', [['product_id' => $this->prod, 'cost' => 3.00, 'qty' => 40]]);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $this->prod, 'qty' => 30]], $this->b1);
        $this->saleReturn($this->wh1, '2026-01-12', [['product_id' => $this->prod, 'qty' => 5]], $this->b1);
        $this->adjustment($this->wh1, '2026-01-15', [['product_id' => $this->prod, 'qty' => 10, 'type' => 'add']]);
        $this->damage($this->wh1, '2026-01-20', [['product_id' => $this->prod, 'qty' => 4]]);

        // WH2 (sucursal b2) — una venta.
        $this->sale($this->wh2, '2026-01-11', [['product_id' => $this->prod, 'qty' => 7]], $this->b2);
    }

    private function build(array $f = []): array
    {
        return $this->svc->build(array_merge([
            'user' => $this->owner,
            'product_id' => $this->prod,
            'branch_id' => $this->b1,
            'from' => null,
            'to' => null,
        ], $f));
    }

    public function test_ledger_reconciles_with_current_on_hand(): void
    {
        $r = $this->build();

        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame(100.0, $r['reconciliation']['ledger_closing_qty']);
        $this->assertSame(100.0, $r['reconciliation']['stock_on_hand']);
        $this->assertSame(0.0, $r['reconciliation']['difference']);
    }

    public function test_reconstructed_opening_balance_is_the_unrecorded_plug(): void
    {
        $r = $this->build();

        $this->assertSame(19.0, $r['summary']['opening_qty']); // 100 − Σin(115) + Σout(34)
        $this->assertTrue($r['quantity_quality']['reconstructed_opening']);
        $this->assertSame('2026-01-05', $r['valuation_quality']['reconstructed_before']);

        $this->assertSame('opening', $r['rows'][0]['kind']);
        $this->assertTrue($r['rows'][0]['is_reconstructed']);
        $this->assertSame(19.0, $r['rows'][0]['balance_qty']);
    }

    public function test_quantity_and_valuation_quality_are_reported_separately(): void
    {
        $r = $this->build();

        // Cantidad: reconciliada aunque el saldo inicial sea reconstruido.
        $this->assertTrue($r['quantity_quality']['reconciled']);
        $this->assertTrue($r['quantity_quality']['reconstructed_opening']);

        // Valorización: aproximada (hay saldo inicial reconstruido) — NUNCA "exacta".
        $this->assertSame('approximate', $r['valuation_quality']['basis']);
        $this->assertNull($r['valuation_quality']['exact_from']);
        $this->assertTrue($r['valuation_quality']['reconstructed_wac']);
        $this->assertStringContainsString('aproximada', $r['valuation_quality']['message']);
    }

    public function test_valuation_is_exact_only_when_every_movement_has_document_cost(): void
    {
        // Producto nuevo: existencia = exactamente la compra registrada (sin plug),
        // sin ventas/ajustes → todos los movimientos tienen costo documental.
        $p = $this->product('Arandela', 5.00);
        $this->onHand($p, $this->wh1, 25);
        $this->purchase($this->wh1, '2026-02-01', [['product_id' => $p, 'cost' => 4.00, 'qty' => 25]]);

        $r = $this->build(['product_id' => $p]);

        $this->assertFalse($r['quantity_quality']['reconstructed_opening']);
        $this->assertSame('exact', $r['valuation_quality']['basis']);
        $this->assertSame('2026-02-01', $r['valuation_quality']['exact_from']);
    }

    public function test_in_and_out_totals_and_running_balance(): void
    {
        $r = $this->build();

        $this->assertSame(115.0, $r['summary']['in_qty']); // 60 + 40 + 5 + 10
        $this->assertSame(34.0, $r['summary']['out_qty']); // 30 + 4
        $this->assertSame(100.0, $r['summary']['closing_qty']);
        $this->assertSame(100.0, end($r['rows'])['balance_qty']);
    }

    public function test_weighted_average_cost_moves_with_purchase_costs_only(): void
    {
        $r = $this->build();

        $this->assertEqualsWithDelta(2.34, $r['summary']['closing_avg_cost'], 0.02);
        $this->assertGreaterThan(200, $r['summary']['closing_value']);
    }

    public function test_historical_value_does_not_change_when_products_cost_changes_later(): void
    {
        $before = $this->build()['summary']['closing_value'];
        DB::table('products')->where('id', $this->prod)->update(['cost' => 999.00]);
        $after = $this->build()['summary']['closing_value'];

        $this->assertSame($before, $after);
    }

    public function test_purchase_return_leaves_stock_at_its_recorded_document_cost(): void
    {
        $p = $this->product('Perno', 1.00);
        $this->onHand($p, $this->wh1, 8);
        $this->purchase($this->wh1, '2026-03-01', [['product_id' => $p, 'cost' => 2.00, 'qty' => 10]]);
        $this->purchaseReturn($this->wh1, '2026-03-05', [['product_id' => $p, 'cost' => 2.00, 'qty' => 2]]);

        $rows = collect($this->build(['product_id' => $p])['rows'])->where('kind', 'movement');
        $ret = $rows->firstWhere('movement_type', 'devolución a proveedor');

        $this->assertSame(2.0, $ret['out_qty']);
        $this->assertSame('documento', $ret['cost_basis']);
        $this->assertEqualsWithDelta(-4.0, $ret['movement_value'], 0.01); // 2 * 2.00 documental
    }

    public function test_each_movement_type_appears_with_the_right_direction(): void
    {
        $rows = collect($this->build()['rows'])->where('kind', 'movement');

        $this->assertSame(100.0, $rows->where('movement_type', 'compra')->sum('in_qty'));
        $this->assertSame(30.0, $rows->firstWhere('movement_type', 'venta')['out_qty']);
        $this->assertSame(5.0, $rows->firstWhere('movement_type', 'devolución de venta')['in_qty']);
        $this->assertSame(10.0, $rows->firstWhere('movement_type', 'ajuste (+)')['in_qty']);
        $this->assertSame(4.0, $rows->firstWhere('movement_type', 'daño')['out_qty']);
    }

    public function test_scope_is_isolated_per_branch(): void
    {
        $wh1 = $this->build(['branch_id' => $this->b1]);
        $wh2 = $this->build(['branch_id' => $this->b2]);

        $this->assertSame(34.0, $wh1['summary']['out_qty']);
        $this->assertSame(0.0, $wh1['reconciliation']['difference']);

        $this->assertSame(57.0, $wh2['summary']['opening_qty']); // 50 − 0 + 7
        $this->assertSame(7.0, $wh2['summary']['out_qty']);
        $this->assertSame(50.0, $wh2['reconciliation']['ledger_closing_qty']);
        $this->assertTrue($wh2['reconciliation']['reconciled']);
    }

    // ---- MODERNO: branch_id != NULL, warehouse_id NULL --------------------

    public function test_modern_pos_sale_with_null_warehouse_appears_in_scope(): void
    {
        $p = $this->product('Clavo', 1.00);
        $this->onHand($p, $this->wh2, 40); // existencia legacy en b2
        // Venta POS moderna: branch b2, warehouse_id NULL.
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b2], '2026-01-14',
            [['product_id' => $p, 'qty' => 10]]);

        $b2 = $this->build(['product_id' => $p, 'branch_id' => $this->b2]);
        $b1 = $this->build(['product_id' => $p, 'branch_id' => $this->b1]);

        // b2 la ve como salida de 10 y reconcilia (40 = 30 tras la venta + 10 plug).
        $sale = collect($b2['rows'])->firstWhere('movement_type', 'venta');
        $this->assertNotNull($sale);
        $this->assertSame(10.0, $sale['out_qty']);
        $this->assertSame(10.0, $b2['summary']['out_qty']);
        $this->assertTrue($b2['reconciliation']['reconciled']);

        // b1 NO la ve.
        $this->assertSame(0.0, $b1['summary']['out_qty']);
    }

    public function test_modern_location_scoped_purchase_and_location_label(): void
    {
        $loc = $this->location('Bodega Norte', $this->b2, $this->wh2);
        $p = $this->product('Grapa', 1.00);
        $this->onHand($p, $this->wh2, 30);
        // Compra moderna: inventory_location_id de b2, warehouse_id NULL.
        $this->purchase(['warehouse_id' => null, 'inventory_location_id' => $loc, 'statut' => 'received'],
            '2026-01-06', [['product_id' => $p, 'cost' => 2.0, 'qty' => 12]]);

        $b2 = $this->build(['product_id' => $p, 'branch_id' => $this->b2]);
        $row = collect($b2['rows'])->firstWhere('movement_type', 'compra');

        $this->assertSame(12.0, $row['in_qty']);
        $this->assertSame('Bodega Norte', $row['location_name']); // InventoryLocation real
        $this->assertSame('modern', $row['location_basis']);
        $this->assertSame('Sucursal Norte', $row['branch_name']);

        // b1 no la ve.
        $b1 = $this->build(['product_id' => $p, 'branch_id' => $this->b1]);
        $this->assertSame(0.0, $b1['summary']['in_qty']);
    }

    public function test_legacy_row_shows_the_warehouse_as_a_legacy_fallback_label(): void
    {
        $row = collect($this->build()['rows'])->firstWhere('movement_type', 'compra');
        $this->assertSame('WH1', $row['location_name']);
        $this->assertSame('legacy', $row['location_basis']);
    }

    public function test_hybrid_tenant_does_not_double_count(): void
    {
        $p = $this->product('Remache', 1.00);
        $this->onHand($p, $this->wh1, 100);
        // Legacy: compra por warehouse.
        $this->purchase($this->wh1, '2026-01-03', [['product_id' => $p, 'cost' => 2.0, 'qty' => 40]]);
        // Moderno: venta por branch (mismo b1), warehouse NULL.
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b1], '2026-01-09',
            [['product_id' => $p, 'qty' => 15]]);

        $r = $this->build(['product_id' => $p, 'branch_id' => $this->b1]);

        // Cada movimiento aparece UNA vez.
        $rows = collect($r['rows'])->where('kind', 'movement');
        $this->assertSame(1, $rows->where('movement_type', 'compra')->count());
        $this->assertSame(1, $rows->where('movement_type', 'venta')->count());
        $this->assertSame(40.0, $r['summary']['in_qty']);
        $this->assertSame(15.0, $r['summary']['out_qty']);
        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame(100.0, $r['reconciliation']['ledger_closing_qty']);
    }

    public function test_internal_transfer_preserves_total_value_across_branches(): void
    {
        $p = $this->product('Tuerca', 1.00);
        $this->onHand($p, $this->wh1, 60);
        $this->onHand($p, $this->wh2, 0);
        $this->purchase($this->wh1, '2026-01-02', [['product_id' => $p, 'cost' => 2.50, 'qty' => 60]]);
        // Traslado interno b1 -> b2 de 20 (transfer_details.cost distinto: 9.99).
        $this->transfer($this->wh1, $this->wh2, '2026-01-10',
            [['product_id' => $p, 'cost' => 9.99, 'qty' => 20]]);

        // Alcance que incluye AMBAS sucursales (sin filtro de sucursal → Owner ve todo).
        $both = $this->svc->build([
            'user' => $this->owner, 'product_id' => $p, 'branch_id' => 0, 'from' => null, 'to' => null,
        ]);

        $rows = collect($both['rows'])->where('kind', 'movement');
        $out = $rows->firstWhere('movement_type', 'traslado (salida)');
        $in = $rows->firstWhere('movement_type', 'traslado (entrada)');

        $this->assertNotNull($out);
        $this->assertNotNull($in);
        // El traslado interno no crea ni destruye valor: |salida| == entrada.
        $this->assertEqualsWithDelta(abs($out['movement_value']), $in['movement_value'], 0.02);
        $this->assertSame('wac', $in['cost_basis']); // NO transfer_details.cost (9.99)

        // Valor total del libro tras el traslado == valor antes del traslado.
        $balAfterPurchase = $rows->firstWhere('movement_type', 'compra')['balance_value'];
        $balAfterIn = $in['balance_value'];
        $this->assertEqualsWithDelta($balAfterPurchase, $balAfterIn, 0.02);
    }

    public function test_transfer_in_alone_falls_back_to_document_cost(): void
    {
        $p = $this->product('Riel', 1.00);
        $this->onHand($p, $this->wh2, 20);
        // Traslado b1 -> b2; sólo miramos b2 → la pierna de salida no está en el libro.
        $this->transfer($this->wh1, $this->wh2, '2026-01-10',
            [['product_id' => $p, 'cost' => 3.00, 'qty' => 8]]);

        $r = $this->build(['product_id' => $p, 'branch_id' => $this->b2]);
        $in = collect($r['rows'])->firstWhere('movement_type', 'traslado (entrada)');

        $this->assertSame(8.0, $in['in_qty']);
        $this->assertSame('documento', $in['cost_basis']);
        $this->assertEqualsWithDelta(24.0, $in['movement_value'], 0.01); // 8 * 3.00
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_owner_without_a_selector_sees_the_whole_tenant_including_branchless_warehouses(): void
    {
        // Almacén legacy SIN sucursal + venta legacy con branch_id NULL apuntando a él.
        $orphanWh = $this->warehouse('WH-Huérfano', null);
        $p = $this->product('Legacy', 1.00);
        $this->onHand($p, $orphanWh, 20);
        $this->sale(['warehouse_id' => $orphanWh, 'branch_id' => null], '2026-01-10', [['product_id' => $p, 'qty' => 5]]);

        // Owner, sin branch_id → unscoped → NO debe ocultar ese movimiento.
        $r = $this->svc->build(['user' => $this->owner, 'product_id' => $p, 'branch_id' => 0, 'from' => null, 'to' => null]);

        $this->assertTrue(collect($r['rows'])->contains('reference', collect($r['rows'])->firstWhere('movement_type', 'venta')['reference'] ?? '_none_'));
        $this->assertSame(5.0, $r['summary']['out_qty']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_missing_product_returns_an_empty_payload_not_an_exception(): void
    {
        $r = $this->svc->build(['user' => $this->owner, 'product_id' => 999999, 'branch_id' => $this->b1, 'from' => null, 'to' => null]);

        $this->assertTrue($r['not_found']);
        $this->assertSame([], $r['rows']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_out_of_order_documents_flag_went_negative_without_clamping_value(): void
    {
        $p = $this->product('Retro', 1.00);
        $this->onHand($p, $this->wh1, 3); // plug pequeño → la venta lo agota
        // Venta el 05, compra retro-fechada el 10 (después): al procesar la venta
        // el saldo intermedio baja de 0.
        $this->sale($this->wh1, '2026-01-05', [['product_id' => $p, 'qty' => 8]], $this->b1);
        $this->purchase($this->wh1, '2026-01-10', [['product_id' => $p, 'cost' => 2.0, 'qty' => 8]]);

        $r = $this->build(['product_id' => $p]);

        $this->assertTrue($r['quantity_quality']['went_negative']);
        $this->assertNotSame('exact', $r['valuation_quality']['basis']); // nunca "exacta" con saldo negativo
        // El saldo final sigue reconciliando con la existencia real (3).
        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame(3.0, $r['reconciliation']['ledger_closing_qty']);
    }

    public function test_on_hand_includes_stock_in_a_branch_location_with_no_warehouse(): void
    {
        $loc = $this->location('Punto Norte (sin almacén)', $this->b2, null);
        $p = $this->product('Móvil', 1.00);
        // Stock moderno en una ubicación SIN almacén (InventoryReadService no la ve).
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $loc, 'product_id' => $p, 'product_variant_id' => null,
            'variant_key' => 0, 'quantity' => 15, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Una compra moderna a esa ubicación (12) para dar movimiento.
        $this->purchase(['warehouse_id' => null, 'inventory_location_id' => $loc, 'statut' => 'received'],
            '2026-01-04', [['product_id' => $p, 'cost' => 2.0, 'qty' => 12]]);

        $r = $this->build(['product_id' => $p, 'branch_id' => $this->b2]);

        // on_hand = 15 (location_stocks), no 0 → reconcilia (opening plug = 3).
        $this->assertSame(15.0, $r['reconciliation']['stock_on_hand']);
        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame(3.0, $r['summary']['opening_qty']);
    }

    public function test_summary_totals_foot_with_the_ledger_when_a_date_filter_is_applied(): void
    {
        $r = $this->build(['from' => '2026-01-14', 'to' => '2026-01-31']);

        $s = $r['summary'];
        $this->assertEqualsWithDelta($s['closing_qty'], $s['opening_qty'] + $s['in_qty'] - $s['out_qty'], 0.001);
        $this->assertSame(10.0, $s['in_qty']);
        $this->assertSame(4.0, $s['out_qty']);
        $this->assertSame(100.0, $s['closing_qty']);
        $this->assertSame(94.0, $s['opening_qty']);
        $this->assertSame(100.0, $s['ledger_closing_qty']);
        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame($s['opening_qty'], $r['rows'][0]['balance_qty']);
    }
}
