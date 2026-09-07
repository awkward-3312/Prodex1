<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reports\InventoryTurnoverReportService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryMovementReportSchema;
use Tests\TestCase;

/**
 * Rotación de inventario — servicio. Métrica operativa en unidades, alcance
 * branch-first / legacy-fallback, casos N/A, ventas POS modernas.
 */
class InventoryTurnoverReportServiceTest extends TestCase
{
    use InventoryMovementReportSchema;

    private InventoryTurnoverReportService $svc;

    private User $owner;

    private int $b1;

    private int $b2;

    private int $wh1;

    private int $wh2;

    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventoryReportSchema();
        $this->svc = app(InventoryTurnoverReportService::class);
        $this->owner = $this->owner();

        $this->b1 = $this->branch('Casa Matriz');
        $this->b2 = $this->branch('Sucursal Norte');
        $this->wh1 = $this->warehouse('WH1', $this->b1);
        $this->wh2 = $this->warehouse('WH2', $this->b2);
        $this->cat = DB::table('categories')->insertGetId(['name' => 'Ferretería', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function exec(array $f = []): array
    {
        return $this->svc->build(array_merge([
            'user' => $this->owner,
            'branch_id' => $this->b1,
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'limit' => -1,
            'sort_field' => 'name',
            'sort_dir' => 'asc',
        ], $f));
    }

    private function rowFor(array $result, int $productId): ?array
    {
        foreach ($result['rows'] as $r) {
            if ($r['product_id'] === $productId) {
                return $r;
            }
        }

        return null;
    }

    public function test_known_fixture_produces_the_documented_operative_formula(): void
    {
        $p = $this->product('Rodamiento', 1.00, ['category_id' => $this->cat]);
        $this->onHand($p, $this->wh1, 100);
        $this->purchase($this->wh1, '2026-01-05', [['product_id' => $p, 'cost' => 2.00, 'qty' => 40]]);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 30]], $this->b1);
        $this->sale($this->wh1, '2026-01-20', [['product_id' => $p, 'qty' => 10]], $this->b1);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(40.0, $row['units_sold']);
        $this->assertSame(100.0, $row['stock_initial']);
        $this->assertSame(100.0, $row['stock_final']);
        $this->assertSame(100.0, $row['avg_stock']);
        $this->assertEqualsWithDelta(0.4, $row['turnover'], 0.0001);
        $this->assertEqualsWithDelta(77.5, $row['days_inventory'], 0.1);
        $this->assertSame('media', $row['classification']);
        $this->assertArrayNotHasKey('financial', $row); // rotación financiera retirada
    }

    public function test_no_sales_in_period_is_na_not_a_misleading_zero(): void
    {
        $p = $this->product('Candado', 3.00);
        $this->onHand($p, $this->wh1, 20);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(0.0, $row['units_sold']);
        $this->assertSame(20.0, $row['avg_stock']);
        $this->assertNull($row['turnover']);          // N/A, NO 0
        $this->assertNull($row['days_inventory']);
        $this->assertNull($row['classification']);    // N/A, NO "baja"
        $this->assertSame('sin ventas en el período', $row['reason']);
    }

    public function test_returns_greater_than_sales_do_not_produce_negative_turnover(): void
    {
        $p = $this->product('Sobre-devuelto', 1.00);
        $this->onHand($p, $this->wh1, 30);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 4]], $this->b1);
        $this->saleReturn($this->wh1, '2026-01-12', [['product_id' => $p, 'qty' => 9]], $this->b1);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(-5.0, $row['units_sold']);   // 4 − 9
        $this->assertNull($row['turnover']);            // nunca negativo
        $this->assertNull($row['classification']);
        $this->assertSame('devoluciones superan ventas', $row['reason']);
    }

    public function test_zero_average_stock_with_no_sales_returns_na(): void
    {
        $p = $this->product('Descontinuado', 1.00);
        $this->onHand($p, $this->wh1, 0);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(0.0, $row['avg_stock']);
        $this->assertNull($row['turnover']);
        $this->assertNull($row['days_inventory']);
        $this->assertNull($row['classification']);
        // Sin ventas es el bloqueo primario; sigue siendo N/A.
        $this->assertContains($row['reason'], ['sin ventas en el período', 'inventario promedio 0']);
    }

    public function test_historical_report_reflects_stock_at_the_period_end_not_today(): void
    {
        $p = $this->product('Histórico', 1.00);
        $this->onHand($p, $this->wh1, 100);                 // existencia HOY
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 10]], $this->b1);
        $this->purchase($this->wh1, '2026-02-15', [['product_id' => $p, 'cost' => 2.0, 'qty' => 40]]); // POSTERIOR al período

        $jan = $this->rowFor($this->exec(['from' => '2026-01-01', 'to' => '2026-01-31']), $p);

        // stock al 31/01 = 100 (hoy) − 40 (compra de febrero) = 60. NO 100.
        $this->assertSame(60.0, $jan['stock_final']);
        // stock al 01/01 = 60 − (−10 venta) = 70.
        $this->assertSame(70.0, $jan['stock_initial']);
        $this->assertSame(65.0, $jan['avg_stock']);         // (70 + 60) / 2
        $this->assertSame(10.0, $jan['units_sold']);
        $this->assertEqualsWithDelta(10 / 65, $jan['turnover'], 0.0001);
        $this->assertSame(100.0, $jan['stock_now']);        // el actual se expone aparte
    }

    public function test_february_report_gives_different_correct_values(): void
    {
        $p = $this->product('Feb', 1.00);
        $this->onHand($p, $this->wh1, 100);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 10]], $this->b1);
        $this->purchase($this->wh1, '2026-02-15', [['product_id' => $p, 'cost' => 2.0, 'qty' => 40]]);
        $this->sale($this->wh1, '2026-02-20', [['product_id' => $p, 'qty' => 5]], $this->b1);

        $feb = $this->rowFor($this->exec(['from' => '2026-02-01', 'to' => '2026-02-28']), $p);

        // nada posterior a febrero → stock al 28/02 = 100.
        $this->assertSame(100.0, $feb['stock_final']);
        // stock al 01/02 = 100 − (compra 40 − venta 5) = 65.
        $this->assertSame(65.0, $feb['stock_initial']);
        $this->assertSame(82.5, $feb['avg_stock']);
        $this->assertSame(5.0, $feb['units_sold']);
    }

    public function test_period_ending_today_still_works(): void
    {
        $p = $this->product('Hoy', 1.00);
        $this->onHand($p, $this->wh1, 40);
        $this->sale($this->wh1, now()->subDays(3)->toDateString(), [['product_id' => $p, 'qty' => 6]], $this->b1);

        $row = $this->rowFor($this->exec(['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]), $p);

        // sin movimientos posteriores a hoy → stock_final = existencia actual.
        $this->assertSame(40.0, $row['stock_final']);
        $this->assertSame(6.0, $row['units_sold']);
        $this->assertNotNull($row['turnover']);
    }

    public function test_historical_reconstruction_works_for_a_warehouse_less_modern_location(): void
    {
        $loc = $this->location('Punto Móvil', $this->b2, null);
        $p = $this->product('LocHist', 1.00);
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $loc, 'product_id' => $p, 'product_variant_id' => null,
            'variant_key' => 0, 'quantity' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // venta moderna en enero (branch b2) + compra moderna posterior (feb, misma location).
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b2, 'inventory_location_id' => $loc], '2026-01-10',
            [['product_id' => $p, 'qty' => 8]]);
        $this->purchase(['warehouse_id' => null, 'inventory_location_id' => $loc, 'statut' => 'received'], '2026-02-05',
            [['product_id' => $p, 'cost' => 2.0, 'qty' => 20]]);

        $jan = $this->rowFor($this->exec(['branch_id' => $this->b2, 'from' => '2026-01-01', 'to' => '2026-01-31']), $p);

        $this->assertSame(30.0, $jan['stock_final']);   // 50 − 20 (compra feb)
        $this->assertSame(38.0, $jan['stock_initial']); // 30 − (−8 venta)
        $this->assertSame(8.0, $jan['units_sold']);
    }

    public function test_invalid_date_returns_an_error(): void
    {
        $this->product('X', 1.0);
        $r = $this->exec(['from' => 'no-es-fecha', 'to' => '2026-01-31']);
        $this->assertArrayHasKey('error', $r);
        $this->assertSame([], $r['rows']);
    }

    public function test_from_after_to_returns_an_error(): void
    {
        $this->product('X', 1.0);
        $r = $this->exec(['from' => '2026-03-01', 'to' => '2026-01-01']);
        $this->assertArrayHasKey('error', $r);
    }

    public function test_future_to_is_clamped_to_today(): void
    {
        $this->product('X', 1.0);
        $r = $this->exec(['from' => now()->subDays(10)->toDateString(), 'to' => now()->addYears(5)->toDateString()]);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertTrue($r['meta']['to_clamped']);
        $this->assertSame(now()->toDateString(), $r['meta']['to']);
    }

    public function test_sort_by_text_and_numeric_fields(): void
    {
        $wh = $this->wh1;
        $b = $this->b1;
        $this->onHand($z = $this->product('Zeta', 1), $wh, 10);
        $this->onHand($a = $this->product('Alfa', 1), $wh, 10);
        $this->sale($wh, '2026-01-10', [['product_id' => $a, 'qty' => 9]], $b); // Alfa vende más
        $this->sale($wh, '2026-01-10', [['product_id' => $z, 'qty' => 1]], $b);

        $byNameAsc = collect($this->exec(['sort_field' => 'name', 'sort_dir' => 'asc'])['rows'])->pluck('name')->all();
        $this->assertSame(['Alfa', 'Zeta'], array_values(array_intersect($byNameAsc, ['Alfa', 'Zeta'])));

        $byNameDesc = collect($this->exec(['sort_field' => 'name', 'sort_dir' => 'desc'])['rows'])->pluck('name')->all();
        $this->assertSame(['Zeta', 'Alfa'], array_values(array_intersect($byNameDesc, ['Zeta', 'Alfa'])));

        $bySoldDesc = collect($this->exec(['sort_field' => 'units_sold', 'sort_dir' => 'desc'])['rows'])->pluck('name')->all();
        $this->assertLessThan(array_search('Zeta', $bySoldDesc, true), array_search('Alfa', $bySoldDesc, true));
    }

    public function test_arbitrary_sort_field_is_ignored(): void
    {
        $this->product('X', 1);
        $r = $this->exec(['sort_field' => 'DROP TABLE products']);
        $this->assertArrayNotHasKey('error', $r); // no rompe; usa el default
    }

    public function test_insufficient_history_returns_na(): void
    {
        $p = $this->product('Migrado', 1.00);
        $this->onHand($p, $this->wh1, 5);
        $this->purchase($this->wh1, '2026-01-06', [['product_id' => $p, 'cost' => 2.0, 'qty' => 100]]);
        $this->sale($this->wh1, '2026-01-15', [['product_id' => $p, 'qty' => 2]], $this->b1);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertNull($row['stock_initial']);
        $this->assertNull($row['avg_stock']);
        $this->assertNull($row['turnover']);
        $this->assertSame('historial insuficiente', $row['reason']);
    }

    public function test_sale_returns_reduce_net_units_sold(): void
    {
        $p = $this->product('Bisagra', 1.00);
        $this->onHand($p, $this->wh1, 50);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 20]], $this->b1);
        $this->saleReturn($this->wh1, '2026-01-12', [['product_id' => $p, 'qty' => 5]], $this->b1);

        $this->assertSame(15.0, $this->rowFor($this->exec(), $p)['units_sold']);
    }

    public function test_branch_scope_isolates_sales_between_branches(): void
    {
        $p = $this->product('Tuerca', 1.00);
        $this->onHand($p, $this->wh1, 40);
        $this->onHand($p, $this->wh2, 40);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 8]], $this->b1);
        $this->sale($this->wh2, '2026-01-10', [['product_id' => $p, 'qty' => 25]], $this->b2);

        $b1 = $this->rowFor($this->exec(['branch_id' => $this->b1]), $p);
        $b2 = $this->rowFor($this->exec(['branch_id' => $this->b2]), $p);

        $this->assertSame(8.0, $b1['units_sold']);
        $this->assertSame(25.0, $b2['units_sold']);
        $this->assertSame(40.0, $b1['stock_final']);
    }

    public function test_modern_pos_sale_with_null_warehouse_counts_only_in_its_branch(): void
    {
        $p = $this->product('Clavo', 1.00);
        $this->onHand($p, $this->wh2, 40);
        // Venta POS moderna: branch b2, warehouse_id NULL.
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b2], '2026-01-14',
            [['product_id' => $p, 'qty' => 10]]);

        $b2 = $this->rowFor($this->exec(['branch_id' => $this->b2]), $p);
        $b1 = $this->rowFor($this->exec(['branch_id' => $this->b1]), $p);

        $this->assertSame(10.0, $b2['units_sold']); // aparece en b2
        $this->assertSame(0.0, $b1['units_sold']);  // NO en b1
        $this->assertEqualsWithDelta(40.0, $b2['stock_final'], 0.001);
    }

    public function test_hybrid_tenant_legacy_and_modern_do_not_double_count(): void
    {
        $p = $this->product('Remache', 1.00);
        $this->onHand($p, $this->wh1, 100);
        // Legacy: venta por warehouse.
        $this->sale($this->wh1, '2026-01-05', [['product_id' => $p, 'qty' => 6]], null);
        // Moderno: venta por branch (b1), warehouse NULL.
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b1], '2026-01-09',
            [['product_id' => $p, 'qty' => 4]]);

        $row = $this->rowFor($this->exec(['branch_id' => $this->b1]), $p);
        $this->assertSame(10.0, $row['units_sold']); // 6 + 4, sin duplicar
    }

    public function test_location_scoped_purchase_is_counted_in_its_branch(): void
    {
        $loc = $this->location('Bodega Norte', $this->b2, $this->wh2);
        $p = $this->product('Grapa', 1.00);
        $this->onHand($p, $this->wh2, 30);
        $this->sale($this->wh2, '2026-01-10', [['product_id' => $p, 'qty' => 12]], $this->b2);
        // Compra moderna en b2 (inventory_location_id), warehouse NULL.
        $this->purchase(['warehouse_id' => null, 'inventory_location_id' => $loc, 'statut' => 'received'],
            '2026-01-06', [['product_id' => $p, 'cost' => 2.0, 'qty' => 12]]);

        $b2 = $this->rowFor($this->exec(['branch_id' => $this->b2]), $p);
        // stock_inicial = final(30) − (compra 12 − venta 12) = 30.
        $this->assertSame(30.0, $b2['stock_initial']);
        $this->assertSame(12.0, $b2['units_sold']);

        // b1: el producto aparece en el universo pero sin actividad ni stock.
        $b1 = $this->rowFor($this->exec(['branch_id' => $this->b1]), $p);
        $this->assertSame(0.0, $b1['units_sold']);
        $this->assertSame(0.0, $b1['stock_final']);
    }

    public function test_location_selector_scopes_sales_to_that_exact_location(): void
    {
        $a1 = $this->location('A1', $this->b1, null);
        $a2 = $this->location('A2', $this->b1, null);
        $p = $this->product('VentaLoc', 1.00);
        $this->locStock($a1, $p, 100);
        $this->locStock($a2, $p, 100);
        // Ventas modernas: 5 en A1, 20 en A2 (misma sucursal b1).
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b1, 'inventory_location_id' => $a1], '2026-01-10',
            [['product_id' => $p, 'qty' => 5]]);
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b1, 'inventory_location_id' => $a2], '2026-01-11',
            [['product_id' => $p, 'qty' => 20]]);
        // Devolución de venta en A2 — NO debe contaminar A1.
        $this->saleReturn(['warehouse_id' => null, 'branch_id' => $this->b1, 'inventory_location_id' => $a2], '2026-01-15',
            [['product_id' => $p, 'qty' => 3]]);

        $r1 = $this->rowFor($this->exec(['branch_id' => null, 'inventory_location_id' => $a1]), $p);
        $r2 = $this->rowFor($this->exec(['branch_id' => null, 'inventory_location_id' => $a2]), $p);
        $rBranch = $this->rowFor($this->exec(['branch_id' => $this->b1]), $p);

        $this->assertSame(5.0, $r1['units_sold']);
        $this->assertSame(17.0, $r2['units_sold']);          // 20 − 3 devolución
        $this->assertSame(22.0, $rBranch['units_sold']);     // 5 + 20 − 3, toda la sucursal
        $this->assertSame(100.0, $r1['stock_now']);
        $this->assertSame(100.0, $r2['stock_now']);
    }

    public function test_location_with_warehouse_reads_its_own_stock_not_product_warehouse(): void
    {
        $l1 = $this->location('L1', $this->b1, $this->wh1);
        $p = $this->product('StockLoc', 1.00);
        $this->onHand($p, $this->wh1, 300);   // product_warehouse del almacén asociado
        $this->locStock($l1, $p, 100);        // stock real de la ubicación pedida

        $row = $this->rowFor($this->exec(['branch_id' => null, 'inventory_location_id' => $l1]), $p);

        $this->assertSame(100.0, $row['stock_now']);    // 100, NO 300, NO 400
        $this->assertSame(100.0, $row['stock_final']);
    }

    public function test_location_without_warehouse_reads_its_stock_via_selector(): void
    {
        $l2 = $this->location('L2', $this->b1, null);
        $p = $this->product('StockLoc2', 1.00);
        $this->locStock($l2, $p, 50);

        $row = $this->rowFor($this->exec(['branch_id' => null, 'inventory_location_id' => $l2]), $p);

        $this->assertSame(50.0, $row['stock_now']);
        $this->assertSame(50.0, $row['stock_final']);
    }

    public function test_non_owner_is_not_unscoped_by_is_all_warehouses(): void
    {
        $staff = $this->nonOwner([$this->b1], 1); // explicit branch A, is_all_warehouses = 1
        $p = $this->product('ScopeChk', 1.00);
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b1], '2026-01-10', [['product_id' => $p, 'qty' => 7]]);
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b2], '2026-01-10', [['product_id' => $p, 'qty' => 50]]);
        $this->onHand($p, $this->wh1, 100);

        $row = $this->rowFor($this->exec(['user' => $staff, 'branch_id' => null]), $p);

        $this->assertSame(7.0, $row['units_sold']);   // sólo su sucursal A
        $this->assertNotSame(57.0, $row['units_sold']); // NO ve la venta moderna de B
    }

    public function test_non_owner_without_explicit_branch_matches_branch_scope_service(): void
    {
        $staff = $this->nonOwner([], 1); // sin user_branches, is_all_warehouses = 1
        $p = $this->product('ScopeChk2', 1.00);
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b1], '2026-01-10', [['product_id' => $p, 'qty' => 3]]);
        $this->sale(['warehouse_id' => null, 'branch_id' => $this->b2], '2026-01-10', [['product_id' => $p, 'qty' => 4]]);

        $row = $this->rowFor($this->exec(['user' => $staff, 'branch_id' => null]), $p);

        // BranchScopeService fallback con is_all_warehouses = 1 → todas las sucursales.
        $this->assertSame(7.0, $row['units_sold']);
    }

    public function test_branch_and_location_of_different_branch_is_rejected(): void
    {
        $locB2 = $this->location('EnB2', $this->b2, null);
        $this->product('X', 1.0);

        $r = $this->exec(['branch_id' => $this->b1, 'inventory_location_id' => $locB2]);

        $this->assertArrayHasKey('error', $r);
        $this->assertSame([], $r['rows']);
    }

    public function test_fully_future_date_range_is_rejected(): void
    {
        $this->product('X', 1.0);
        $r = $this->exec(['from' => now()->addYears(1)->toDateString(), 'to' => now()->addYears(1)->addMonths(2)->toDateString()]);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('futuro', $r['error']);
    }

    public function test_meta_documents_formula_thresholds_and_scope(): void
    {
        $this->product('X', 1.0);
        $meta = $this->exec()['meta'];

        $this->assertStringContainsString('inventario promedio', $meta['formula']);
        $this->assertSame(30, $meta['thresholds']['alta_max_dias']);
        $this->assertSame(90, $meta['thresholds']['media_max_dias']);
        $this->assertSame(31, $meta['period_days']);
        $this->assertSame([$this->b1], $meta['branch_ids']);
        $this->assertArrayNotHasKey('financial_note', $meta);
    }

    public function test_services_are_never_counted(): void
    {
        DB::table('products')->insert([
            'name' => 'Instalación', 'code' => 'SRV', 'type' => 'is_service', 'cost' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, $this->exec()['totalRows']);
    }
}
