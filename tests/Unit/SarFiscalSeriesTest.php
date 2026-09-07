<?php

namespace Tests\Unit;

use App\Exceptions\SarFiscalException;
use App\Models\Sale;
use App\Models\SarAuthorization;
use App\Services\PosAwareSarFiscalSaleService;
use App\Services\SarFiscalNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The fiscal correlativo belongs to the AUTHORISATION of a fiscal series, never
 * to a cash drawer or a branch.
 *
 *   - many cash drawers of one series consume the SAME counter
 *   - a different series has an independent counter
 *   - the number is assigned only when the sale is confirmed (no reservation)
 *   - a retried sale never consumes a second number
 *   - a rolled-back failed sale leaves the counter untouched
 *   - when the active CAI runs out, PRODEX switches atomically to the prepared
 *     "next" authorisation of the same series
 *
 * True OS-level parallelism is covered by SarFiscalConcurrencyTest (real MySQL,
 * separate worker processes). These are the deterministic logic guarantees.
 */
class SarFiscalSeriesTest extends TestCase
{
    private PosAwareSarFiscalSaleService $sales;

    private SarFiscalNumberService $numbers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->sales = app(PosAwareSarFiscalSaleService::class);
        $this->numbers = app(SarFiscalNumberService::class);
        $this->profile();
    }

    // ---------------------------------------------------------------- tests ---

    public function test_many_drawers_of_one_series_share_the_same_counter(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 100]);
        $d1 = $this->drawer($branch, $location, 'Caja 1', $point);
        $d2 = $this->drawer($branch, $location, 'Caja 2', $point);
        $d3 = $this->drawer($branch, $location, 'Caja 3', $point);

        $n1 = $this->issue($branch, $location, $d1);
        $n2 = $this->issue($branch, $location, $d2);
        $n3 = $this->issue($branch, $location, $d3);
        $n4 = $this->issue($branch, $location, $d1);

        $this->assertSame(['001-001-01-00000001', '001-001-01-00000002', '001-001-01-00000003', '001-001-01-00000004'],
            [$n1->fiscal_number, $n2->fiscal_number, $n3->fiscal_number, $n4->fiscal_number]);
        $this->assertSame(1, SarAuthorization::where('point_of_issue_id', $point)->count());
        $this->assertSame(5, (int) SarAuthorization::where('point_of_issue_id', $point)->value('next_number'));
    }

    public function test_a_different_series_has_an_independent_counter(): void
    {
        [$bA, $lA, $pA] = $this->series('001', '001', ['CAI-A', 1000, 1999]);
        $dA = $this->drawer($bA, $lA, 'Caja A', $pA);

        [$bB, $lB, $pB] = $this->series('002', '001', ['CAI-B', 5000, 5999], 'Sucursal B');
        $dB = $this->drawer($bB, $lB, 'Caja B', $pB);

        $a1 = $this->issue($bA, $lA, $dA);
        $b1 = $this->issue($bB, $lB, $dB);
        $a2 = $this->issue($bA, $lA, $dA);

        $this->assertSame('001-001-01-00001000', $a1->fiscal_number);
        $this->assertSame('002-001-01-00005000', $b1->fiscal_number);
        $this->assertSame('001-001-01-00001001', $a2->fiscal_number);
        $this->assertSame('CAI-A', $a1->cai);
        $this->assertSame('CAI-B', $b1->cai);
        $this->assertNotSame($a1->authorization_id, $b1->authorization_id);
    }

    public function test_the_number_tracks_completion_order_not_start_order(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 100]);
        $dA = $this->drawer($branch, $location, 'Caja A', $point);
        $dB = $this->drawer($branch, $location, 'Caja B', $point);

        // Caja A "starts" its sale first...
        $saleA = $this->makeSale($branch, $location, $dA);
        // ...Caja B starts later...
        $saleB = $this->makeSale($branch, $location, $dB);

        // ...but Caja B confirms first -> Caja B gets the lower number.
        $docB = $this->sales->issueIfEnabled($saleB->fresh(), $dB);
        $docA = $this->sales->issueIfEnabled($saleA->fresh(), $dA);

        $this->assertSame('001-001-01-00000001', $docB->fiscal_number);
        $this->assertSame('001-001-01-00000002', $docA->fiscal_number);
    }

    public function test_a_retried_sale_never_consumes_a_second_number(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 100]);
        $d = $this->drawer($branch, $location, 'Caja 1', $point);
        $sale = $this->makeSale($branch, $location, $d);

        $first = $this->sales->issueIfEnabled($sale->fresh(), $d);
        $again = $this->sales->issueIfEnabled($sale->fresh(), $d);
        $andAgain = $this->sales->issueIfEnabled($sale->fresh(), $d);

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->id, $andAgain->id);
        $this->assertSame($first->fiscal_number, $again->fiscal_number);
        $this->assertSame(1, DB::table('sar_fiscal_documents')->count());
        $this->assertSame(2, (int) SarAuthorization::where('point_of_issue_id', $point)->value('next_number'));
    }

    public function test_a_rolled_back_failed_sale_leaves_the_counter_untouched(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 100]);
        $d = $this->drawer($branch, $location, 'Caja 1', $point);

        // Allocate one real number so the counter is at 2.
        $this->issue($branch, $location, $d);
        $this->assertSame(2, (int) SarAuthorization::where('point_of_issue_id', $point)->value('next_number'));

        // A sale-creation transaction that allocates a number, then blows up.
        try {
            DB::transaction(function () use ($branch, $location, $d) {
                $sale = $this->makeSale($branch, $location, $d);
                $this->sales->issueIfEnabled($sale->fresh(), $d);
                throw new \RuntimeException('boom after allocation');
            });
            $this->fail('the failing transaction should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom after allocation', $e->getMessage());
        }

        // No phantom document, no skipped correlativo: the next real sale gets 2.
        $this->assertSame(1, DB::table('sar_fiscal_documents')->count());
        $this->assertSame(2, (int) SarAuthorization::where('point_of_issue_id', $point)->value('next_number'));

        $recovered = $this->issue($branch, $location, $d);
        $this->assertSame('001-001-01-00000002', $recovered->fiscal_number);
    }

    public function test_a_drawer_cannot_consume_the_cai_of_another_series(): void
    {
        [$bA, $lA, $pA] = $this->series('001', '001', ['CAI-A', 1, 100]);
        $dA = $this->drawer($bA, $lA, 'Caja A', $pA);

        [$bB, $lB, $pB] = $this->series('002', '001', ['CAI-B', 1, 100], 'Sucursal B');
        $dB = $this->drawer($bB, $lB, 'Caja B', $pB);

        // A sale in branch A that somehow points at branch B's drawer is refused
        // before any number is allocated.
        $this->expectException(SarFiscalException::class);
        $this->expectExceptionMessage('pertenece a otra sucursal');
        $this->sales->issueIfEnabled($this->makeSale($bA, $lA, $dB)->fresh(), $dB);
    }

    public function test_an_exhausted_range_stops_invoicing_when_there_is_no_next(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 3]);
        $d = $this->drawer($branch, $location, 'Caja 1', $point);

        $this->assertSame('001-001-01-00000001', $this->issue($branch, $location, $d)->fiscal_number);
        $this->assertSame('001-001-01-00000002', $this->issue($branch, $location, $d)->fiscal_number);
        $this->assertSame('001-001-01-00000003', $this->issue($branch, $location, $d)->fiscal_number);

        // The 3rd sale consumed the last number and already retired the CAI;
        // the 4th sale is refused and no new document is created.
        try {
            $this->issue($branch, $location, $d);
            $this->fail('an exhausted range must stop invoicing');
        } catch (SarFiscalException $e) {
            $this->assertMatchesRegularExpression('/agotado|una autorización sar activa/i', $e->getMessage());
        }
        $this->assertSame(3, DB::table('sar_fiscal_documents')->count());
        $this->assertSame('exhausted', SarAuthorization::where('point_of_issue_id', $point)->value('status'));

        // A manually mis-set counter (past range_end, still "active") is also refused.
        SarAuthorization::where('point_of_issue_id', $point)->update(['status' => 'active', 'next_number' => 99]);
        try {
            $this->issue($branch, $location, $d);
            $this->fail('a counter past range_end must stop invoicing');
        } catch (SarFiscalException $e) {
            $this->assertStringContainsString('agotado', $e->getMessage());
        }
    }

    public function test_an_expired_cai_stops_invoicing(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 100]);
        SarAuthorization::where('point_of_issue_id', $point)->update(['deadline' => now()->subDay()->toDateString()]);
        $d = $this->drawer($branch, $location, 'Caja 1', $point);

        try {
            $this->issue($branch, $location, $d);
            $this->fail('an expired CAI must stop invoicing');
        } catch (SarFiscalException $e) {
            $this->assertStringContainsString('venció', $e->getMessage());
        }
        $this->assertSame(0, DB::table('sar_fiscal_documents')->count());
    }

    public function test_it_switches_to_the_prepared_next_authorisation_atomically(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 3]);
        $active = SarAuthorization::where('point_of_issue_id', $point)->first();
        $prepared = SarAuthorization::create([
            'point_of_issue_id' => $point, 'document_type' => '01', 'cai' => 'CAI-A-NEXT',
            'range_start' => 4, 'range_end' => 6, 'next_number' => 4,
            'deadline' => now()->addYear()->toDateString(), 'status' => 'prepared',
        ]);
        $d = $this->drawer($branch, $location, 'Caja 1', $point);

        $this->assertSame('001-001-01-00000001', $this->issue($branch, $location, $d)->fiscal_number);
        $this->assertSame('001-001-01-00000002', $this->issue($branch, $location, $d)->fiscal_number);
        $third = $this->issue($branch, $location, $d); // consumes the last number of CAI-A
        $this->assertSame('001-001-01-00000003', $third->fiscal_number);
        $this->assertSame('CAI-A', $third->cai);

        // Next sale: the active CAI is spent -> PRODEX switches to CAI-A-NEXT.
        $fourth = $this->issue($branch, $location, $d);
        $this->assertSame('001-001-01-00000004', $fourth->fiscal_number);
        $this->assertSame('CAI-A-NEXT', $fourth->cai);

        $active->refresh();
        $prepared->refresh();
        $this->assertSame('exhausted', $active->status);
        $this->assertSame('active', $prepared->status);
        $this->assertSame($prepared->id, (int) $active->superseded_by_id);
        $this->assertNotNull($prepared->activated_at);

        // And it keeps going on the new CAI.
        $this->assertSame('001-001-01-00000005', $this->issue($branch, $location, $d)->fiscal_number);
    }

    public function test_ten_sequential_sales_get_ten_unique_consecutive_numbers(): void
    {
        [$branch, $location, $point] = $this->series('001', '001', ['CAI-A', 1, 1000]);
        $drawers = [
            $this->drawer($branch, $location, 'Caja 1', $point),
            $this->drawer($branch, $location, 'Caja 2', $point),
            $this->drawer($branch, $location, 'Caja 3', $point),
        ];

        $numbers = [];
        for ($i = 0; $i < 10; $i++) {
            $numbers[] = (int) $this->issue($branch, $location, $drawers[$i % 3])->sequence;
        }

        $this->assertSame(range(1, 10), $numbers);
        $this->assertSame(10, count(array_unique($numbers)));
        $this->assertSame(10, DB::table('sar_fiscal_documents')->count());
        $this->assertSame(11, (int) SarAuthorization::where('point_of_issue_id', $point)->value('next_number'));
    }

    public function test_a_legacy_warehouse_point_still_invoices_after_the_refactor(): void
    {
        // A point created the old way: warehouse_id + cash_drawer_id, no pivot.
        $branchWh = DB::table('warehouses')->insertGetId(['name' => 'WH Legacy', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        $drawerId = (int) DB::table('cash_drawers')->insertGetId([
            'branch_id' => null, 'inventory_location_id' => null, 'warehouse_id' => $branchWh,
            'name' => 'Caja Legacy', 'code' => 'LEG', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pointId = (int) DB::table('sar_points_of_issue')->insertGetId([
            'establishment_code' => '050', 'point_code' => '001', 'name' => 'Legacy', 'address' => 'Dir',
            'branch_id' => null, 'inventory_location_id' => null, 'warehouse_id' => $branchWh, 'cash_drawer_id' => $drawerId,
            'active' => true, 'is_auto_managed' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sar_authorizations')->insert([
            'point_of_issue_id' => $pointId, 'document_type' => '01', 'cai' => 'CAI-LEG',
            'range_start' => 1, 'range_end' => 10, 'next_number' => 1,
            'deadline' => now()->addYear()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A non-POS sale (no branch / location) -> legacy warehouse resolver.
        $sale = $this->makeSale(null, null, null, $branchWh);
        DB::table('sales')->where('id', $sale->id)->update(['is_pos' => 0]);

        $doc = $this->sales->issueIfEnabled(Sale::find($sale->id), $drawerId);
        $this->assertSame('CAI-LEG', $doc->cai);
        $this->assertSame('050-001-01-00000001', $doc->fiscal_number);
    }

    // ------------------------------------------------------------- helpers ---

    private function issue(?int $branch, ?int $location, ?int $drawer): \App\Models\SarFiscalDocument
    {
        return $this->sales->issueIfEnabled($this->makeSale($branch, $location, $drawer)->fresh(), $drawer);
    }

    /** Create an enabled fiscal series with an active authorisation; returns [branchId, locationId, pointId]. */
    private function series(string $est, string $point, array $auth, string $branchName = 'Sucursal Principal'): array
    {
        [$cai, $rangeStart, $rangeEnd] = $auth;

        $branchId = (int) DB::table('branches')->insertGetId([
            'name' => $branchName, 'code' => strtoupper(substr($branchName, 0, 3)), 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = (int) DB::table('inventory_locations')->insertGetId([
            'branch_id' => $branchId, 'name' => 'Piso', 'type' => 'sales_floor', 'is_sellable' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->where('id', $branchId)->update(['default_inventory_location_id' => $locationId]);
        DB::table('sar_branch_settings')->insert(['branch_id' => $branchId, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

        $pointId = (int) DB::table('sar_points_of_issue')->insertGetId([
            'establishment_code' => $est, 'point_code' => $point, 'name' => 'Serie '.$est.'-'.$point, 'address' => 'Dir',
            'branch_id' => $branchId, 'inventory_location_id' => $locationId, 'warehouse_id' => null, 'cash_drawer_id' => null,
            'active' => true, 'is_auto_managed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sar_authorizations')->insert([
            'point_of_issue_id' => $pointId, 'document_type' => '01', 'cai' => $cai,
            'range_start' => $rangeStart, 'range_end' => $rangeEnd, 'next_number' => $rangeStart,
            'deadline' => now()->addYear()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$branchId, $locationId, $pointId];
    }

    private function drawer(int $branchId, int $locationId, string $name, int $pointId): int
    {
        $id = (int) DB::table('cash_drawers')->insertGetId([
            'branch_id' => $branchId, 'inventory_location_id' => $locationId, 'name' => $name,
            'code' => strtoupper(str_replace(' ', '', $name)).$branchId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sar_point_cash_drawers')->insert([
            'sar_point_of_issue_id' => $pointId, 'cash_drawer_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeSale(?int $branchId, ?int $locationId, ?int $drawerId, ?int $warehouseId = null): Sale
    {
        $userId = (int) (DB::table('users')->value('id') ?: DB::table('users')->insertGetId(['username' => 'cajero', 'created_at' => now(), 'updated_at' => now()]));
        $clientId = (int) (DB::table('clients')->value('id') ?: DB::table('clients')->insertGetId(['name' => 'Cliente', 'tax_number' => '0801199912345', 'created_at' => now(), 'updated_at' => now()]));
        $productId = (int) (DB::table('products')->value('id') ?: DB::table('products')->insertGetId(['code' => 'P1', 'name' => 'Producto exento', 'fiscal_tax_category' => 'exempt', 'TaxNet' => 0, 'tax_method' => '1', 'created_at' => now(), 'updated_at' => now()]));
        $methodId = (int) (DB::table('payment_methods')->value('id') ?: DB::table('payment_methods')->insertGetId(['name' => 'Efectivo', 'created_at' => now(), 'updated_at' => now()]));

        $saleId = (int) DB::table('sales')->insertGetId([
            'date' => now()->toDateString(), 'time' => now()->format('H:i:s'), 'Ref' => 'SL-'.uniqid(),
            'is_pos' => 1, 'client_id' => $clientId, 'user_id' => $userId,
            'warehouse_id' => $warehouseId, 'branch_id' => $branchId, 'inventory_location_id' => $locationId, 'cash_drawer_id' => $drawerId,
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

    private function profile(): void
    {
        DB::table('sar_fiscal_profiles')->insert([
            'enabled' => true, 'rtn' => '08019999999999', 'legal_name' => 'Negocio SA',
            'head_office_address' => 'Tegucigalpa', 'invoice_settings' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
            $t->string('establishment_code', 3)->nullable();
            $t->string('point_code', 3)->nullable();
            $t->string('name');
            $t->text('address');
            $t->unsignedInteger('branch_id')->nullable();
            $t->unsignedInteger('inventory_location_id')->nullable();
            $t->unsignedInteger('warehouse_id')->nullable();
            $t->unsignedInteger('cash_drawer_id')->nullable();
            $t->boolean('active')->default(false);
            $t->boolean('is_auto_managed')->default(false);
            $t->timestamps();
        });
        Schema::create('sar_branch_settings', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('branch_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('sar_point_cash_drawers', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('sar_point_of_issue_id');
            $t->unsignedInteger('cash_drawer_id')->unique();
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
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('exhausted_at')->nullable();
            $t->unsignedBigInteger('superseded_by_id')->nullable();
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
}
