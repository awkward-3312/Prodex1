<?php

namespace Tests\Feature;

use App\Services\Reports\ValuedKardexReportService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryMovementReportSchema;
use Tests\TestCase;

/**
 * Kardex valorizado — servicio. Verifica la réplica cronológica con WAC, la
 * reconciliación contra la existencia real, el saldo inicial reconstruido y que
 * el costo histórico NO se revaloriza con `products.cost` actual.
 */
class ValuedKardexReportServiceTest extends TestCase
{
    use InventoryMovementReportSchema;

    private ValuedKardexReportService $svc;

    private int $wh1;

    private int $wh2;

    private int $prod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventoryReportSchema();
        $this->svc = app(ValuedKardexReportService::class);

        $b1 = $this->branch('Casa Matriz');
        $b2 = $this->branch('Sucursal Norte');
        $this->wh1 = $this->warehouse('WH1', $b1);
        $this->wh2 = $this->warehouse('WH2', $b2);

        $this->prod = $this->product('Tornillo', 1.00);      // products.cost = 1.00 (NO debe usarse)
        $this->onHand($this->prod, $this->wh1, 100);
        $this->onHand($this->prod, $this->wh2, 50);

        // WH1 — historia documental (legacy product_warehouse).
        $this->purchase($this->wh1, '2026-01-05', [['product_id' => $this->prod, 'cost' => 2.00, 'qty' => 60]]);
        $this->purchase($this->wh1, '2026-01-08', [['product_id' => $this->prod, 'cost' => 3.00, 'qty' => 40]]);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $this->prod, 'qty' => 30]], $b1);
        $this->saleReturn($this->wh1, '2026-01-12', [['product_id' => $this->prod, 'qty' => 5]], $b1);
        $this->adjustment($this->wh1, '2026-01-15', [['product_id' => $this->prod, 'qty' => 10, 'type' => 'add']]);
        $this->damage($this->wh1, '2026-01-20', [['product_id' => $this->prod, 'qty' => 4]]);

        // WH2 — sólo una venta, para probar alcance por sucursal.
        $this->sale($this->wh2, '2026-01-11', [['product_id' => $this->prod, 'qty' => 7]], $b2);
    }

    private function build(array $warehouseIds): array
    {
        return $this->svc->build([
            'product_id' => $this->prod,
            'warehouse_ids' => $warehouseIds,
            'from' => null,
            'to' => null,
        ]);
    }

    public function test_ledger_reconciles_with_current_on_hand(): void
    {
        $r = $this->build([$this->wh1]);

        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame(100.0, $r['reconciliation']['ledger_closing_qty']);
        $this->assertSame(100.0, $r['reconciliation']['stock_on_hand']);
        $this->assertSame(0.0, $r['reconciliation']['difference']);
    }

    public function test_reconstructed_opening_balance_is_the_unrecorded_plug(): void
    {
        $r = $this->build([$this->wh1]);

        // opening = on_hand(100) − Σin(60+40+5+10=115) + Σout(30+4=34) = 19
        $this->assertSame(19.0, $r['summary']['opening_qty']);
        $this->assertTrue($r['data_quality']['is_reconstructed_opening']);
        $this->assertSame('2026-01-05', $r['data_quality']['exact_from']);

        $opening = $r['rows'][0];
        $this->assertSame('opening', $opening['kind']);
        $this->assertTrue($opening['is_reconstructed']);
        $this->assertSame(19.0, $opening['balance_qty']);
    }

    public function test_in_and_out_totals_and_running_balance(): void
    {
        $r = $this->build([$this->wh1]);

        $this->assertSame(115.0, $r['summary']['in_qty']);   // 60 + 40 + 5 (dev. venta) + 10 (ajuste +)
        $this->assertSame(34.0, $r['summary']['out_qty']);   // 30 (venta) + 4 (daño)
        $this->assertSame(100.0, $r['summary']['closing_qty']);

        // El saldo de unidades corre sin saltos y termina en la existencia real.
        $last = end($r['rows']);
        $this->assertSame(100.0, $last['balance_qty']);
    }

    public function test_weighted_average_cost_moves_with_purchase_costs_only(): void
    {
        $r = $this->build([$this->wh1]);

        // Tras 19@2.00 (inicial) + 60@2.00 + 40@3.00: WAC ≈ 2.336 (redondeo a los
        // decimales de precio del tenant). Valor final ≈ 100 * WAC ≈ 233.6.
        // products.cost (1.00) NO interviene.
        $this->assertEqualsWithDelta(2.34, $r['summary']['closing_avg_cost'], 0.02);
        $this->assertEqualsWithDelta(233.6, $r['summary']['closing_value'], 1.5);
        $this->assertGreaterThan(200, $r['summary']['closing_value']);
    }

    public function test_historical_value_does_not_change_when_products_cost_changes_later(): void
    {
        $before = $this->build([$this->wh1])['summary']['closing_value'];

        DB::table('products')->where('id', $this->prod)->update(['cost' => 999.00]);

        $after = $this->build([$this->wh1])['summary']['closing_value'];

        $this->assertSame($before, $after, 'El kardex no debe revalorizarse con products.cost actual');
    }

    public function test_each_movement_type_appears_with_the_right_direction(): void
    {
        $rows = collect($this->build([$this->wh1])['rows'])->where('kind', 'movement');

        $purchases = $rows->where('movement_type', 'compra');
        $this->assertSame(2, $purchases->count());
        $this->assertSame(100.0, $purchases->sum('in_qty'));            // 60 + 40
        $this->assertSame(0.0, (float) $purchases->sum('out_qty'));

        $sale = $rows->firstWhere('movement_type', 'venta');
        $this->assertSame(30.0, $sale['out_qty']);
        $this->assertNull($sale['in_qty']);

        $this->assertSame(5.0, $rows->firstWhere('movement_type', 'devolución de venta')['in_qty']);
        $this->assertSame(10.0, $rows->firstWhere('movement_type', 'ajuste (+)')['in_qty']);
        $this->assertSame(4.0, $rows->firstWhere('movement_type', 'daño')['out_qty']);
    }

    public function test_scope_is_isolated_per_branch_warehouse(): void
    {
        $wh1 = $this->build([$this->wh1]);
        $wh2 = $this->build([$this->wh2]);

        // La venta de 7 en WH2 no toca el kardex de WH1.
        $this->assertSame(0.0, $wh1['reconciliation']['difference']);
        $this->assertSame(34.0, $wh1['summary']['out_qty']);

        // WH2: on_hand 50, sólo una venta de 7 → opening plug = 57, cierre 50.
        $this->assertSame(57.0, $wh2['summary']['opening_qty']);
        $this->assertSame(7.0, $wh2['summary']['out_qty']);
        $this->assertSame(50.0, $wh2['reconciliation']['ledger_closing_qty']);
        $this->assertTrue($wh2['reconciliation']['reconciled']);
    }

    public function test_no_double_counting_when_a_stray_modern_stock_row_exists(): void
    {
        // Fila moderna huérfana: el almacén sigue en modo legacy (sin
        // inventory_transition_states) → InventoryReadService la ignora.
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => 999, 'product_id' => $this->prod,
            'product_variant_id' => null, 'variant_key' => 0, 'quantity' => 12345,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->build([$this->wh1]);
        $this->assertSame(100.0, $r['reconciliation']['stock_on_hand']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_transfer_in_uses_document_cost_and_still_reconciles(): void
    {
        // Traslado WH2 → WH1 de 8 @ 2.50 el 01-18.
        $this->transfer($this->wh2, $this->wh1, '2026-01-18', [['product_id' => $this->prod, 'cost' => 2.50, 'qty' => 8]]);

        $r = $this->build([$this->wh1]);

        // Nuevo Σin = 115 + 8 = 123 ; opening plug = 100 − 123 + 34 = 11.
        $this->assertSame(123.0, $r['summary']['in_qty']);
        $this->assertSame(11.0, $r['summary']['opening_qty']);
        $this->assertSame(100.0, $r['reconciliation']['ledger_closing_qty']);
        $this->assertTrue($r['reconciliation']['reconciled']);

        $transferRow = collect($r['rows'])->firstWhere('movement_type', 'traslado (entrada)');
        $this->assertNotNull($transferRow);
        $this->assertSame(8.0, $transferRow['in_qty']);
        // Valor del movimiento = 8 * 2.50 = 20 (costo documental, no WAC).
        $this->assertEqualsWithDelta(20.0, $transferRow['movement_value'], 0.01);
        $this->assertSame('documento', $transferRow['cost_basis']);
    }

    public function test_period_window_collapses_earlier_movements_into_a_period_opening_row(): void
    {
        $r = $this->svc->build([
            'product_id' => $this->prod,
            'warehouse_ids' => [$this->wh1],
            'from' => '2026-01-14',
            'to' => '2026-01-31',
        ]);

        $first = $r['rows'][0];
        $this->assertSame('period_opening', $first['kind']);
        // Sólo ajuste (01-15) y daño (01-20) quedan en la ventana.
        $kinds = array_column(array_slice($r['rows'], 1), 'movement_type');
        $this->assertSame(['ajuste (+)', 'daño'], $kinds);
        // El cierre sigue reconciliando con la existencia real.
        $last = end($r['rows']);
        $this->assertSame(100.0, $last['balance_qty']);
    }

    public function test_summary_totals_foot_with_the_ledger_when_a_date_filter_is_applied(): void
    {
        // Ventana que empieza tras la compra y las primeras ventas: sólo el
        // ajuste (+10, 01-15) y el daño (−4, 01-20) caen dentro.
        $r = $this->svc->build([
            'product_id' => $this->prod,
            'warehouse_ids' => [$this->wh1],
            'from' => '2026-01-14',
            'to' => '2026-01-31',
        ]);

        $s = $r['summary'];
        // saldo inicial del período + entradas − salidas = saldo final del período
        $this->assertEqualsWithDelta(
            $s['closing_qty'],
            $s['opening_qty'] + $s['in_qty'] - $s['out_qty'],
            0.001,
            'el resumen del kardex debe cuadrar dentro de la ventana de fechas'
        );
        $this->assertSame(10.0, $s['in_qty']);   // ajuste (+)
        $this->assertSame(4.0, $s['out_qty']);   // daño
        $this->assertSame(100.0, $s['closing_qty']);              // saldo al 01-20
        $this->assertSame(94.0, $s['opening_qty']);               // saldo tras la venta del 01-10 (367→...→94)

        // El saldo del LIBRO COMPLETO sigue expuesto aparte y reconcilia.
        $this->assertSame(100.0, $s['ledger_closing_qty']);
        $this->assertTrue($r['reconciliation']['reconciled']);

        // El período_opening de la tabla coincide con el resumen.
        $this->assertSame($s['opening_qty'], $r['rows'][0]['balance_qty']);
    }
}
