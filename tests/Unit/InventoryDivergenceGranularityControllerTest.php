<?php

namespace Tests\Unit;

use App\Http\Controllers\InventoryVisibilityController;
use App\Http\Controllers\TransferLocationController;
use App\Models\InventoryLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feedback PR #77: la divergencia legado ↔ por ubicación debe medirse POR
 * ALMACÉN contra el AGREGADO de todas sus InventoryLocation, nunca contra una
 * sola ubicación, y sin compensar entre almacenes.
 */
class InventoryDivergenceGranularityControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->string('type')->default('is_single');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_variants', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 14, 3)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->string('name');
            $t->string('type')->default('storage');
            $t->boolean('is_sellable')->default(false);
            $t->boolean('is_quarantine')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 14, 3)->default(0);
            $t->decimal('reserved_quantity', 14, 3)->default(0);
            $t->timestamps();
        });
    }

    private function product(int $id, string $code, string $name): void
    {
        DB::table('products')->insert([
            'id' => $id, 'code' => $code, 'name' => $name, 'type' => 'is_single',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacy(int $productId, int $warehouseId, float $qty, ?int $variantId = null): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'product_variant_id' => $variantId,
            'qte' => $qty, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function variant(int $id, int $productId, string $code, string $name): void
    {
        DB::table('product_variants')->insert([
            'id' => $id, 'product_id' => $productId, 'code' => $code, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loc(int $warehouseId, string $code): int
    {
        return (int) DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $warehouseId, 'branch_id' => null, 'code' => $code, 'name' => $code,
            'type' => 'storage', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stock(int $locationId, int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locationId, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0), 'quantity' => $qty, 'reserved_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- Blocker 1: variantes no se compensan entre sí ---------------------

    /**
     * Caso A: VAR A legacy 50 / location 30 (+20) ; VAR B legacy 20 / location 40 (-20).
     * A nivel producto legacy 70 / location 70, pero pending debe ser 20, NO 0.
     */
    public function test_caseA_visibility_does_not_net_across_variants(): void
    {
        $this->product(1, 'IPH', 'iPhone');
        $this->variant(900, 1, 'IPH-A', 'A');
        $this->variant(901, 1, 'IPH-B', 'B');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(1, 1, 50, 900);
        $this->legacy(1, 1, 20, 901);
        $l = $this->loc(1, 'MAIN');
        $this->stock($l, 1, 30, 900);
        $this->stock($l, 1, 40, 901);

        $p = collect($this->search('IPH')['products'])->firstWhere('id', 1);
        $this->assertTrue($p['legacy_pending']);
        $this->assertEquals(20.0, $p['legacy_pending_quantity']); // no 0
    }

    /** Caso B: VAR A 50/50 ; VAR B 20/20 => pending 0. */
    public function test_caseB_visibility_variant_parity_is_pending_zero(): void
    {
        $this->product(1, 'IPH', 'iPhone');
        $this->variant(900, 1, 'IPH-A', 'A');
        $this->variant(901, 1, 'IPH-B', 'B');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(1, 1, 50, 900);
        $this->legacy(1, 1, 20, 901);
        $l = $this->loc(1, 'MAIN');
        $this->stock($l, 1, 50, 900);
        $this->stock($l, 1, 20, 901);

        $p = collect($this->search('IPH')['products'])->firstWhere('id', 1);
        $this->assertFalse($p['legacy_pending']);
        $this->assertEquals(0.0, $p['legacy_pending_quantity']);
    }

    /**
     * Caso C: VAR A está en STORAGE2 pero no en MAIN. Buscar el código de VAR A
     * desde MAIN => kind=other_location, NO divergence.
     */
    public function test_caseC_transfer_search_variant_in_other_location(): void
    {
        $this->product(1, 'IPH', 'iPhone');
        $this->variant(900, 1, 'IPH-A', 'A');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(1, 1, 40, 900);
        $main = $this->loc(1, 'MAIN');
        $st2 = $this->loc(1, 'STORAGE2');
        $this->stock($st2, 1, 40, 900); // toda la variante A en STORAGE2

        $pending = $this->legacyPendingForLocation(InventoryLocation::findOrFail($main));
        $this->assertCount(1, $pending);
        $this->assertSame(900, $pending[0]['product_variant_id']);
        $this->assertSame('IPH-A', $pending[0]['code']);
        $this->assertSame('other_location', $pending[0]['kind']);
        $this->assertSame(0.0, $pending[0]['pending_quantity']);
        $this->assertSame(40.0, $pending[0]['warehouse_location_quantity']);
    }

    /** Y si la variante A de verdad diverge (legacy 40 / warehouse-locations 25) => divergence. */
    public function test_transfer_search_variant_real_divergence(): void
    {
        $this->product(1, 'IPH', 'iPhone');
        $this->variant(900, 1, 'IPH-A', 'A');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(1, 1, 40, 900);
        $main = $this->loc(1, 'MAIN');
        $st2 = $this->loc(1, 'STORAGE2');
        $this->stock($main, 1, 15, 900);
        $this->stock($st2, 1, 10, 900); // agregado 25 < legacy 40

        $pending = $this->legacyPendingForLocation(InventoryLocation::findOrFail($main));
        $this->assertSame('divergence', $pending[0]['kind']);
        $this->assertSame(15.0, $pending[0]['pending_quantity']);
        $this->assertSame(25.0, $pending[0]['warehouse_location_quantity']);
        $this->assertSame(15.0, $pending[0]['selected_location_quantity']);
    }

    /**
     * Caso 5: WH1 legacy 100 / location 0, WH2 legacy 0 / location 100.
     * pending global NO puede ser 0 por compensación: WH1 aporta +100.
     */
    public function test_case5_inventory_visibility_does_not_net_divergence_across_warehouses(): void
    {
        $this->product(1, 'IPH', 'iPhone');
        DB::table('warehouses')->insert([['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()],
                                         ['id' => 2, 'name' => 'CD2', 'created_at' => now(), 'updated_at' => now()]]);
        $this->legacy(1, 1, 100);          // WH1 legacy 100
        $this->legacy(1, 2, 0);            // WH2 legacy 0
        $l2 = $this->loc(2, 'MAIN');
        $this->stock($l2, 1, 100);         // WH2 location 100

        $data = $this->search('IPH');
        $p = collect($data['products'])->firstWhere('id', 1);

        $this->assertTrue($p['legacy_pending']);
        $this->assertEquals(100.0, $p['legacy_pending_quantity']); // NO 0 por cancelación
        // company_available se deriva sólo de filas location-native (WH2 = 100).
        $this->assertEquals(100.0, $p['company_available']);
    }

    /**
     * Contra-caso: un único almacén con MAIN 70 + QUARANTINE 30 vs legacy 100
     * está reconciliado → sin señal de divergencia.
     */
    public function test_split_across_locations_of_one_warehouse_is_not_flagged(): void
    {
        $this->product(1, 'IPH', 'iPhone');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(1, 1, 100);
        $m = $this->loc(1, 'MAIN'); $q = $this->loc(1, 'QUAR');
        $this->stock($m, 1, 70); $this->stock($q, 1, 30);

        $p = collect($this->search('IPH')['products'])->firstWhere('id', 1);
        $this->assertFalse($p['legacy_pending']);
        $this->assertEquals(0.0, $p['legacy_pending_quantity']);
    }

    /**
     * Caso 3: legacy 100, MAIN 0, STORAGE2 100. El almacén está reconciliado.
     * Buscar desde MAIN NO debe decir "divergencia": debe ser "other_location".
     */
    public function test_case3_transfer_search_reports_other_location_not_divergence(): void
    {
        $this->product(7, 'AAA', 'Prod A');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(7, 1, 100);
        $main = $this->loc(1, 'MAIN');
        $st2 = $this->loc(1, 'STORAGE2');
        $this->stock($st2, 7, 100);   // todo el stock está en STORAGE2, MAIN 0

        $location = InventoryLocation::findOrFail($main);
        $pending = $this->legacyPendingForLocation($location);

        $this->assertCount(1, $pending);
        $this->assertSame('other_location', $pending[0]['kind']);
        $this->assertSame(0.0, $pending[0]['pending_quantity']);
        $this->assertSame(100.0, $pending[0]['warehouse_location_quantity']);
        $this->assertSame(0.0, $pending[0]['selected_location_quantity']);
    }

    /**
     * Y si de verdad hay divergencia (legacy 130, warehouse-locations 100),
     * kind = 'divergence' con pending 30.
     */
    public function test_transfer_search_reports_real_divergence_against_warehouse_aggregate(): void
    {
        $this->product(7, 'AAA', 'Prod A');
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'CD1', 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy(7, 1, 130);
        $main = $this->loc(1, 'MAIN');
        $st2 = $this->loc(1, 'STORAGE2');
        $this->stock($main, 7, 60);
        $this->stock($st2, 7, 40);   // warehouse aggregate 100 < legacy 130

        $pending = $this->legacyPendingForLocation(InventoryLocation::findOrFail($main));

        $this->assertSame('divergence', $pending[0]['kind']);
        $this->assertSame(30.0, $pending[0]['pending_quantity']);
        $this->assertSame(100.0, $pending[0]['warehouse_location_quantity']);
        $this->assertSame(60.0, $pending[0]['selected_location_quantity']);
    }

    // --- helpers -----------------------------------------------------------

    private function search(string $q): array
    {
        $user = new \stdClass();
        $user->default_branch_id = null;
        $request = Request::create('/api/inventory-visibility/search', 'GET', ['q' => $q]);
        $request->setUserResolver(fn () => $user);

        return (array) json_decode((new InventoryVisibilityController)->search($request)->getContent(), true);
    }

    private function legacyPendingForLocation(InventoryLocation $location): array
    {
        $m = new ReflectionMethod(TransferLocationController::class, 'legacyPendingForLocation');
        return (array) $m->invoke(new TransferLocationController, $location);
    }
}
