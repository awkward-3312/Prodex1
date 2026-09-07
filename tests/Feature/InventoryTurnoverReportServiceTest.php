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

    public function test_product_with_stock_but_no_sales_is_low_rotation_not_infinite(): void
    {
        $p = $this->product('Candado', 3.00);
        $this->onHand($p, $this->wh1, 20);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(0.0, $row['units_sold']);
        $this->assertSame(20.0, $row['avg_stock']);
        $this->assertSame(0.0, $row['turnover']);
        $this->assertNull($row['days_inventory']);
        $this->assertSame('baja', $row['classification']);
        $this->assertSame('sin ventas en el período', $row['reason']);
    }

    public function test_zero_average_stock_returns_na(): void
    {
        $p = $this->product('Descontinuado', 1.00);
        $this->onHand($p, $this->wh1, 0);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(0.0, $row['avg_stock']);
        $this->assertNull($row['turnover']);
        $this->assertNull($row['days_inventory']);
        $this->assertNull($row['classification']);
        $this->assertSame('inventario promedio 0', $row['reason']);
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
