<?php

namespace Tests\Unit;

use App\Exceptions\SarFiscalException;
use App\Models\Sale;
use App\Services\PosAwareSarFiscalSaleService;
use App\Services\SarFiscalSaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SAR / CAI multi-branch invoicing.
 *
 *   Branch -> InventoryLocation -> CashDrawer -> SAR Point -> Authorization -> CAI -> range
 *
 * Each branch consumes only its own CAI / correlativo range. Every mismatch is
 * rejected before a fiscal document is created.
 */
class SarMultiBranchFiscalTest extends TestCase
{
    private SarFiscalSaleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->service = app(SarFiscalSaleService::class);

        $this->assertInstanceOf(PosAwareSarFiscalSaleService::class, $this->service, 'Runtime binding must be the POS-aware resolver.');
    }

    // ---------------------------------------------------------------- schema ---

    private function buildSchema(): void
    {
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->tinyInteger('is_active')->default(1);
            $t->integer('default_inventory_location_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('name')->nullable();
            $t->string('type')->nullable();
            $t->tinyInteger('is_sellable')->default(1);
            $t->tinyInteger('is_active')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('cash_drawers', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->tinyInteger('is_active')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('username')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('clients', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('tax_number')->nullable();
            $t->string('identification_type')->nullable();
            $t->string('identification_number')->nullable();
            $t->string('adresse')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('sar_registry_number')->nullable();
            $t->string('exoneration_registry_number')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('code')->nullable();
            $t->string('name')->nullable();
            $t->string('fiscal_tax_category')->nullable();
            $t->decimal('TaxNet', 8, 2)->default(0);
            $t->string('tax_method')->default('1');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('payment_methods', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('sales', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('Ref')->nullable();
            $t->tinyInteger('is_pos')->default(1);
            $t->integer('client_id')->nullable();
            $t->integer('user_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('branch_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->integer('cash_drawer_id')->nullable();
            $t->decimal('GrandTotal', 15, 2)->default(0);
            $t->decimal('TaxNet', 15, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('discount', 15, 2)->default(0);
            $t->string('discount_Method')->nullable();
            $t->decimal('discount_from_points', 15, 2)->default(0);
            $t->decimal('promotion_discount', 15, 2)->default(0);
            $t->decimal('shipping', 15, 2)->default(0);
            $t->text('fiscal_exemption_data')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('sale_details', function ($t) {
            $t->increments('id');
            $t->integer('sale_id')->nullable();
            $t->integer('product_id')->nullable();
            $t->decimal('quantity', 15, 2)->default(1);
            $t->decimal('price', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->decimal('TaxNet', 8, 2)->default(0);
            $t->decimal('discount', 15, 2)->default(0);
            $t->string('discount_method')->nullable();
            $t->string('tax_method')->nullable();
            $t->string('fiscal_tax_category')->nullable();
            $t->decimal('fiscal_tax_rate', 8, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('payment_sales', function ($t) {
            $t->increments('id');
            $t->integer('sale_id')->nullable();
            $t->string('Ref')->nullable();
            $t->decimal('montant', 15, 2)->default(0);
            $t->decimal('change', 15, 2)->default(0);
            $t->integer('payment_method_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('sar_fiscal_profiles', function ($t) {
            $t->increments('id');
            $t->boolean('enabled')->default(false);
            $t->string('rtn')->nullable();
            $t->string('legal_name')->nullable();
            $t->string('trade_name')->nullable();
            $t->text('head_office_address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('invoice_settings')->nullable();
            $t->timestamps();
        });
        Schema::create('sar_points_of_issue', function ($t) {
            $t->increments('id');
            $t->string('establishment_code', 3);
            $t->string('point_code', 3);
            $t->string('name');
            $t->text('address');
            $t->unsignedInteger('branch_id')->nullable();
            $t->unsignedInteger('inventory_location_id')->nullable();
            $t->unsignedInteger('warehouse_id')->nullable();
            $t->unsignedInteger('cash_drawer_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('sar_authorizations', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('point_of_issue_id');
            $t->string('document_type', 2)->default('01');
            $t->string('cai', 64);
            $t->unsignedBigInteger('range_start');
            $t->unsignedBigInteger('range_end');
            $t->unsignedBigInteger('next_number');
            $t->date('authorization_date')->nullable();
            $t->date('deadline');
            $t->string('status')->default('draft');
            $t->timestamps();
        });
        Schema::create('sar_fiscal_documents', function ($t) {
            $t->increments('id');
            $t->integer('sale_id');
            $t->unsignedBigInteger('authorization_id');
            $t->unsignedBigInteger('sequence');
            $t->string('fiscal_number', 25);
            $t->string('cai', 64);
            $t->date('deadline');
            $t->string('status')->default('issued');
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 500)->nullable();
            $t->unsignedInteger('voided_by')->nullable();
            $t->text('issuer_snapshot');
            $t->text('customer_snapshot');
            $t->text('sale_snapshot');
            $t->timestamps();
        });
    }

    // -------------------------------------------------------------- fixtures ---

    private function profile(bool $enabled = true): void
    {
        DB::table('sar_fiscal_profiles')->insert([
            'enabled' => $enabled, 'rtn' => '08019999999999', 'legal_name' => 'Negocio SA',
            'head_office_address' => 'Tegucigalpa', 'invoice_settings' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branch(string $name): int
    {
        return (int) DB::table('branches')->insertGetId(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function location(int $branchId, string $name = 'Piso de venta'): int
    {
        return (int) DB::table('inventory_locations')->insertGetId(['branch_id' => $branchId, 'name' => $name, 'type' => 'sales_floor', 'is_sellable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function drawer(int $branchId, ?int $locationId, string $name): int
    {
        return (int) DB::table('cash_drawers')->insertGetId(['branch_id' => $branchId, 'inventory_location_id' => $locationId, 'name' => $name, 'code' => strtoupper(str_replace(' ', '', $name)), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function point(array $o): int
    {
        return (int) DB::table('sar_points_of_issue')->insertGetId(array_merge([
            'establishment_code' => '000', 'point_code' => '001', 'name' => 'Punto', 'address' => 'Dir',
            'branch_id' => null, 'inventory_location_id' => null, 'warehouse_id' => null, 'cash_drawer_id' => null,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function authorization(int $pointId, array $o = []): int
    {
        return (int) DB::table('sar_authorizations')->insertGetId(array_merge([
            'point_of_issue_id' => $pointId, 'document_type' => '01', 'cai' => 'CAI-'.strtoupper(bin2hex(random_bytes(4))),
            'range_start' => 1, 'range_end' => 100, 'next_number' => 1,
            'deadline' => now()->addYear()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function sale(int $branchId, int $locationId, int $drawerId): Sale
    {
        $userId = (int) DB::table('users')->insertGetId(['username' => 'cajero', 'created_at' => now(), 'updated_at' => now()]);
        $clientId = (int) DB::table('clients')->insertGetId(['name' => 'Cliente', 'tax_number' => '0801199912345', 'created_at' => now(), 'updated_at' => now()]);
        $productId = (int) DB::table('products')->insertGetId(['code' => 'P1', 'name' => 'Producto exento', 'fiscal_tax_category' => 'exempt', 'TaxNet' => 0, 'tax_method' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $methodId = (int) DB::table('payment_methods')->insertGetId(['name' => 'Efectivo', 'created_at' => now(), 'updated_at' => now()]);

        $saleId = (int) DB::table('sales')->insertGetId([
            'date' => now()->toDateString(), 'time' => now()->format('H:i:s'), 'Ref' => 'SL-'.$branchId.'-'.uniqid(),
            'is_pos' => 1, 'client_id' => $clientId, 'user_id' => $userId,
            'warehouse_id' => null, 'branch_id' => $branchId, 'inventory_location_id' => $locationId, 'cash_drawer_id' => $drawerId,
            'GrandTotal' => 100, 'TaxNet' => 0, 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_details')->insert([
            'sale_id' => $saleId, 'product_id' => $productId, 'quantity' => 1, 'price' => 100, 'total' => 100,
            'TaxNet' => 0, 'discount' => 0, 'fiscal_tax_category' => 'exempt', 'fiscal_tax_rate' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_sales')->insert([
            'sale_id' => $saleId, 'Ref' => 'PS-'.$saleId, 'montant' => 100, 'change' => 0, 'payment_method_id' => $methodId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Sale::findOrFail($saleId);
    }

    // ---------------------------------------------------------------- tests ---

    public function test_1_and_2_each_branch_issues_from_its_own_cai(): void
    {
        $this->profile();

        $b1 = $this->branch('Sucursal 1');
        $l1 = $this->location($b1);
        $d1 = $this->drawer($b1, $l1, 'Caja 1');
        $p1 = $this->point(['establishment_code' => '001', 'point_code' => '001', 'branch_id' => $b1, 'inventory_location_id' => $l1, 'cash_drawer_id' => $d1]);
        $a1 = $this->authorization($p1, ['cai' => 'CAI-A', 'range_start' => 1000, 'range_end' => 1999, 'next_number' => 1000]);

        $b2 = $this->branch('Sucursal 2');
        $l2 = $this->location($b2);
        $d2 = $this->drawer($b2, $l2, 'Caja 2');
        $p2 = $this->point(['establishment_code' => '002', 'point_code' => '001', 'branch_id' => $b2, 'inventory_location_id' => $l2, 'cash_drawer_id' => $d2]);
        $a2 = $this->authorization($p2, ['cai' => 'CAI-B', 'range_start' => 5000, 'range_end' => 5999, 'next_number' => 5000]);

        $doc1 = $this->service->issueIfEnabled($this->sale($b1, $l1, $d1), $d1);
        $doc2 = $this->service->issueIfEnabled($this->sale($b2, $l2, $d2), $d2);

        $this->assertSame('CAI-A', $doc1->cai);
        $this->assertSame('001-001-01-00001000', $doc1->fiscal_number);
        $this->assertSame((int) $a1, (int) $doc1->authorization_id);

        $this->assertSame('CAI-B', $doc2->cai);
        $this->assertSame('002-001-01-00005000', $doc2->fiscal_number);
        $this->assertSame((int) $a2, (int) $doc2->authorization_id);

        $this->assertSame(1001, (int) DB::table('sar_authorizations')->where('id', $a1)->value('next_number'));
        $this->assertSame(5001, (int) DB::table('sar_authorizations')->where('id', $a2)->value('next_number'));
    }

    public function test_3_drawer_from_another_branch_is_rejected(): void
    {
        $this->profile();
        $b1 = $this->branch('Sucursal 1');
        $l1 = $this->location($b1);
        $d1 = $this->drawer($b1, $l1, 'Caja 1');
        $this->authorization($this->point(['branch_id' => $b1, 'inventory_location_id' => $l1, 'cash_drawer_id' => $d1]));

        $b2 = $this->branch('Sucursal 2');
        $l2 = $this->location($b2);
        $d2 = $this->drawer($b2, $l2, 'Caja 2');

        $this->expectException(SarFiscalException::class);
        $this->expectExceptionMessage('pertenece a otra sucursal');
        $this->service->issueIfEnabled($this->sale($b2, $l2, $d1), $d1); // sale in branch 2, drawer of branch 1
    }

    public function test_4_drawer_without_a_sar_point_is_rejected(): void
    {
        $this->profile();
        $b = $this->branch('Sucursal Sur');
        $l = $this->location($b);
        $d = $this->drawer($b, $l, 'Caja Sur');

        $this->expectException(SarFiscalException::class);
        $this->expectExceptionMessage('No hay un punto de emisión SAR activo para');
        $this->service->issueIfEnabled($this->sale($b, $l, $d), $d);
    }

    public function test_5_point_without_an_active_authorization_is_rejected(): void
    {
        $this->profile();
        $b = $this->branch('S');
        $l = $this->location($b);
        $d = $this->drawer($b, $l, 'C');
        $p = $this->point(['branch_id' => $b, 'inventory_location_id' => $l, 'cash_drawer_id' => $d]);
        $this->authorization($p, ['status' => 'draft']); // never activated

        $this->expectException(SarFiscalException::class);
        $this->expectExceptionMessage('No existe una autorización SAR activa');
        $this->service->issueIfEnabled($this->sale($b, $l, $d), $d);
    }

    public function test_6_expired_or_exhausted_cai_is_rejected(): void
    {
        $this->profile();

        // Expired
        $b = $this->branch('S');
        $l = $this->location($b);
        $d = $this->drawer($b, $l, 'C');
        $p = $this->point(['branch_id' => $b, 'inventory_location_id' => $l, 'cash_drawer_id' => $d]);
        $expired = $this->authorization($p, ['deadline' => now()->subDay()->toDateString()]);

        try {
            $this->service->issueIfEnabled($this->sale($b, $l, $d), $d);
            $this->fail('Expired CAI should be rejected.');
        } catch (SarFiscalException $e) {
            $this->assertStringContainsString('venció', $e->getMessage());
        }
        $this->assertSame('expired', DB::table('sar_authorizations')->where('id', $expired)->value('status'));

        // Exhausted (next_number past range_end)
        $b2 = $this->branch('S2');
        $l2 = $this->location($b2);
        $d2 = $this->drawer($b2, $l2, 'C2');
        $p2 = $this->point(['establishment_code' => '009', 'branch_id' => $b2, 'inventory_location_id' => $l2, 'cash_drawer_id' => $d2]);
        $exhausted = $this->authorization($p2, ['range_start' => 1, 'range_end' => 10, 'next_number' => 11]);

        try {
            $this->service->issueIfEnabled($this->sale($b2, $l2, $d2), $d2);
            $this->fail('Exhausted range should be rejected.');
        } catch (SarFiscalException $e) {
            $this->assertStringContainsString('agotado', $e->getMessage());
        }
        $this->assertSame('exhausted', DB::table('sar_authorizations')->where('id', $exhausted)->value('status'));
    }

    public function test_7_two_branches_never_share_correlativos(): void
    {
        $this->profile();

        $b1 = $this->branch('B1');
        $l1 = $this->location($b1);
        $d1 = $this->drawer($b1, $l1, 'D1');
        $a1 = $this->authorization($this->point(['establishment_code' => '001', 'branch_id' => $b1, 'inventory_location_id' => $l1, 'cash_drawer_id' => $d1]), ['cai' => 'CAI-1', 'range_start' => 1, 'range_end' => 50, 'next_number' => 1]);

        $b2 = $this->branch('B2');
        $l2 = $this->location($b2);
        $d2 = $this->drawer($b2, $l2, 'D2');
        $a2 = $this->authorization($this->point(['establishment_code' => '002', 'branch_id' => $b2, 'inventory_location_id' => $l2, 'cash_drawer_id' => $d2]), ['cai' => 'CAI-2', 'range_start' => 1, 'range_end' => 50, 'next_number' => 1]);

        $doc1a = $this->service->issueIfEnabled($this->sale($b1, $l1, $d1), $d1);
        $doc2a = $this->service->issueIfEnabled($this->sale($b2, $l2, $d2), $d2);
        $doc1b = $this->service->issueIfEnabled($this->sale($b1, $l1, $d1), $d1);

        $this->assertSame(1, (int) $doc1a->sequence);
        $this->assertSame(1, (int) $doc2a->sequence);
        $this->assertSame(2, (int) $doc1b->sequence);

        // Different CAI, different authorization — no shared correlative.
        $this->assertNotSame($doc1a->authorization_id, $doc2a->authorization_id);
        $this->assertSame('CAI-1', $doc1a->cai);
        $this->assertSame('CAI-2', $doc2a->cai);

        // The per-authorization sequence uniqueness still holds.
        $this->assertSame(2, DB::table('sar_fiscal_documents')->where('authorization_id', $a1)->count());
        $this->assertSame(1, DB::table('sar_fiscal_documents')->where('authorization_id', $a2)->count());
    }

    public function test_8_legacy_point_still_works_after_the_backfill(): void
    {
        // Simulate a point created before the multi-branch change: warehouse_id +
        // cash_drawer_id only. The migration backfill copies branch/location from
        // the CashDrawer.
        $this->profile();
        $b = $this->branch('Legacy');
        $l = $this->location($b);
        DB::table('warehouses')->insert(['id' => 77, 'branch_id' => $b, 'name' => 'WH Legacy', 'created_at' => now(), 'updated_at' => now()]);
        $d = $this->drawer($b, $l, 'Caja Legacy');

        $pointId = $this->point([
            'establishment_code' => '050', 'point_code' => '001',
            'branch_id' => null, 'inventory_location_id' => null,
            'warehouse_id' => 77, 'cash_drawer_id' => $d,
        ]);
        $this->authorization($pointId, ['cai' => 'CAI-LEG', 'range_start' => 1, 'range_end' => 10, 'next_number' => 1]);

        // Run the backfill portion of the real migration.
        $migration = require dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_07_000000_link_sar_points_to_branch_location_drawer.php';
        $migration->up();

        $point = DB::table('sar_points_of_issue')->where('id', $pointId)->first();
        $this->assertSame($b, (int) $point->branch_id, 'branch_id backfilled from the cash drawer');
        $this->assertSame($l, (int) $point->inventory_location_id, 'inventory_location_id backfilled from the cash drawer');

        // And a modern POS sale from that drawer now issues cleanly.
        $doc = $this->service->issueIfEnabled($this->sale($b, $l, $d), $d);
        $this->assertSame('CAI-LEG', $doc->cai);
        $this->assertSame('050-001-01-00000001', $doc->fiscal_number);
    }
}
