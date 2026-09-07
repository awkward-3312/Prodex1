<?php

namespace Tests\Feature;

use App\Services\Reports\InventoryTurnoverReportService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryMovementReportSchema;
use Tests\TestCase;

/**
 * Rotación de inventario — servicio. Fórmula operativa en unidades con inventario
 * promedio reconstruido, más los casos "sin dato" (N/A, nunca infinito ni 0
 * engañoso) y la métrica financiera WAC en columnas aparte.
 */
class InventoryTurnoverReportServiceTest extends TestCase
{
    use InventoryMovementReportSchema;

    private InventoryTurnoverReportService $svc;

    private int $wh1;

    private int $wh2;

    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventoryReportSchema();
        $this->svc = app(InventoryTurnoverReportService::class);

        $b1 = $this->branch('Casa Matriz');
        $b2 = $this->branch('Sucursal Norte');
        $this->wh1 = $this->warehouse('WH1', $b1);
        $this->wh2 = $this->warehouse('WH2', $b2);
        $this->cat = DB::table('categories')->insertGetId(['name' => 'Ferretería', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function exec(array $overrides = []): array
    {
        return $this->svc->build(array_merge([
            'warehouse_ids' => [$this->wh1],
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'limit' => -1,
            'sort_field' => 'name',
            'sort_dir' => 'asc',
        ], $overrides));
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
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 30]]);
        $this->sale($this->wh1, '2026-01-20', [['product_id' => $p, 'qty' => 10]]);

        $row = $this->rowFor($this->exec(), $p);

        // net período = +40 compra − 40 ventas = 0 → stock_inicial = 100, promedio = 100.
        $this->assertSame(40.0, $row['units_sold']);
        $this->assertSame(100.0, $row['stock_initial']);
        $this->assertSame(100.0, $row['stock_final']);
        $this->assertSame(100.0, $row['avg_stock']);
        // rotación = 40 / 100 = 0.4 ; días = 31 / 0.4 = 77.5 → "media".
        $this->assertEqualsWithDelta(0.4, $row['turnover'], 0.0001);
        $this->assertEqualsWithDelta(77.5, $row['days_inventory'], 0.1);
        $this->assertSame('media', $row['classification']);

        // Financiera WAC: costo unitario = 2.00 (compra) → CdV = 80, inv. valorizado = 200.
        $this->assertEqualsWithDelta(2.00, $row['financial']['unit_cost'], 0.001);
        $this->assertEqualsWithDelta(80.0, $row['financial']['cogs'], 0.01);
        $this->assertEqualsWithDelta(200.0, $row['financial']['avg_stock_value'], 0.01);
        $this->assertEqualsWithDelta(0.4, $row['financial']['turnover'], 0.001);
    }

    public function test_product_with_stock_but_no_sales_is_low_rotation_not_infinite(): void
    {
        $p = $this->product('Candado', 3.00);
        $this->onHand($p, $this->wh1, 20);

        $row = $this->rowFor($this->exec(), $p);

        $this->assertSame(0.0, $row['units_sold']);
        $this->assertSame(20.0, $row['avg_stock']);
        $this->assertSame(0.0, $row['turnover']);      // 0, no infinito
        $this->assertNull($row['days_inventory']);      // no divide por 0
        $this->assertSame('baja', $row['classification']);
        $this->assertSame('sin ventas en el período', $row['reason']);
    }

    public function test_zero_average_stock_returns_na_not_a_misleading_zero(): void
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
        // Compra de 100 en el período pero sólo 5 en existencia → el saldo
        // inicial reconstruido sería −95: historial documental insuficiente.
        $p = $this->product('Migrado', 1.00);
        $this->onHand($p, $this->wh1, 5);
        $this->purchase($this->wh1, '2026-01-06', [['product_id' => $p, 'cost' => 2.0, 'qty' => 100]]);
        $this->sale($this->wh1, '2026-01-15', [['product_id' => $p, 'qty' => 2]]);

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
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 20]]);
        $this->saleReturn($this->wh1, '2026-01-12', [['product_id' => $p, 'qty' => 5]]);

        $row = $this->rowFor($this->exec(), $p);
        $this->assertSame(15.0, $row['units_sold']);   // 20 − 5
    }

    public function test_branch_scope_isolates_sales_between_warehouses(): void
    {
        $p = $this->product('Tuerca', 1.00);
        $this->onHand($p, $this->wh1, 40);
        $this->onHand($p, $this->wh2, 40);
        $this->sale($this->wh1, '2026-01-10', [['product_id' => $p, 'qty' => 8]]);
        $this->sale($this->wh2, '2026-01-10', [['product_id' => $p, 'qty' => 25]]);

        $wh1Row = $this->rowFor($this->exec(['warehouse_ids' => [$this->wh1]]), $p);
        $wh2Row = $this->rowFor($this->exec(['warehouse_ids' => [$this->wh2]]), $p);

        $this->assertSame(8.0, $wh1Row['units_sold']);
        $this->assertSame(25.0, $wh2Row['units_sold']);
        $this->assertSame(40.0, $wh1Row['stock_final']);
    }

    public function test_meta_documents_formula_and_thresholds(): void
    {
        $this->product('X', 1.0);
        $meta = $this->exec()['meta'];

        $this->assertStringContainsString('inventario promedio', $meta['formula']);
        $this->assertSame(30, $meta['thresholds']['alta_max_dias']);
        $this->assertSame(90, $meta['thresholds']['media_max_dias']);
        $this->assertSame(31, $meta['period_days']);
    }

    public function test_services_are_never_counted(): void
    {
        DB::table('products')->insert([
            'name' => 'Instalación', 'code' => 'SRV', 'type' => 'is_service', 'cost' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->exec();
        $this->assertSame(0, $result['totalRows']);
    }
}
