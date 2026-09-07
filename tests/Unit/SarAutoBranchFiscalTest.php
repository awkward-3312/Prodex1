<?php

namespace Tests\Unit;

use App\Exceptions\SarFiscalException;
use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\SarBranchSetting;
use App\Models\SarPointOfIssue;
use App\Models\Sale;
use App\Services\PosAwareSarFiscalSaleService;
use App\Services\SarBranchFiscalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRODEX manages the SAR technical structure per branch. The tenant only toggles
 * "Facturación SAR habilitada" and types its authorised data.
 *
 *   Perfil habilitado          -> cada sucursal activa aparece (deshabilitada)
 *   Sucursal habilitada        -> 1 punto gestionado + pivote a sus cajas activas
 *   Caja nueva                 -> se cubre sola
 *   Deshabilitar una sucursal  -> no se puede facturar desde ella
 *   Sucursal A nunca consume el CAI de la Sucursal B
 *   Configuraciones existentes se conservan
 */
class SarAutoBranchFiscalTest extends TestCase
{
    private SarBranchFiscalService $sync;

    private PosAwareSarFiscalSaleService $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->sync = app(SarBranchFiscalService::class);
        $this->sales = app(PosAwareSarFiscalSaleService::class);
    }

    // ---------------------------------------------------------------- schema ---

    private function buildSchema(): void
    {
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->string('address')->nullable();
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

    private function branch(string $name, bool $active = true): int
    {
        return (int) DB::table('branches')->insertGetId([
            'name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'is_active' => $active ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function location(int $branchId, string $name = 'Piso de venta'): int
    {
        $id = (int) DB::table('inventory_locations')->insertGetId([
            'branch_id' => $branchId, 'name' => $name, 'type' => 'sales_floor', 'is_sellable' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->where('id', $branchId)->whereNull('default_inventory_location_id')
            ->update(['default_inventory_location_id' => $id]);

        return $id;
    }

    private function drawer(int $branchId, ?int $locationId, string $name, bool $active = true): int
    {
        return (int) DB::table('cash_drawers')->insertGetId([
            'branch_id' => $branchId, 'inventory_location_id' => $locationId, 'name' => $name,
            'code' => strtoupper(str_replace(' ', '', $name)), 'is_active' => $active ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function authorizationForBranch(int $branchId, array $o = []): int
    {
        $point = SarPointOfIssue::where('branch_id', $branchId)->firstOrFail();

        return (int) DB::table('sar_authorizations')->insertGetId(array_merge([
            'point_of_issue_id' => $point->id, 'document_type' => '01',
            'cai' => 'CAI-'.strtoupper(bin2hex(random_bytes(4))),
            'range_start' => 1, 'range_end' => 100, 'next_number' => 1,
            'deadline' => now()->addYear()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function configureBranch(int $branchId, string $est, string $pt, array $authOverrides = []): void
    {
        $this->sync->setBranchEnabled($branchId, true);
        $point = SarPointOfIssue::where('branch_id', $branchId)->firstOrFail();
        $point->update(['establishment_code' => $est, 'point_code' => $pt]);
        $this->sync->syncBranch(Branch::findOrFail($branchId));
        $this->authorizationForBranch($branchId, $authOverrides);
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

    public function test_1_enabling_the_profile_registers_every_active_branch_disabled(): void
    {
        $this->profile();
        $b1 = $this->branch('Sucursal Principal');
        $b2 = $this->branch('Sucursal Norte');
        $inactive = $this->branch('Sucursal Cerrada', false);

        $this->sync->syncAllActiveBranches();

        $this->assertTrue(SarBranchSetting::where('branch_id', $b1)->exists());
        $this->assertTrue(SarBranchSetting::where('branch_id', $b2)->exists());
        $this->assertFalse(SarBranchSetting::where('branch_id', $inactive)->exists(), 'Inactive branches are not registered.');

        $this->assertFalse((bool) SarBranchSetting::where('branch_id', $b1)->value('enabled'));
        $this->assertFalse((bool) SarBranchSetting::where('branch_id', $b2)->value('enabled'));
        $this->assertSame(0, SarPointOfIssue::count(), 'No point is created until a branch is enabled.');
    }

    public function test_2_a_branch_created_later_appears_automatically_disabled(): void
    {
        $this->profile();
        $this->branch('Sucursal Principal');
        $this->sync->syncAllActiveBranches();

        $late = $this->branch('Sucursal Tardía');
        $this->sync->syncAllActiveBranches();

        $setting = SarBranchSetting::where('branch_id', $late)->first();
        $this->assertNotNull($setting);
        $this->assertFalse((bool) $setting->enabled);
    }

    public function test_3_a_cash_drawer_added_later_is_covered_automatically(): void
    {
        $this->profile();
        $b = $this->branch('Sucursal Principal');
        $l = $this->location($b);
        $d1 = $this->drawer($b, $l, 'Caja 1');

        $this->sync->setBranchEnabled($b, true);
        $point = SarPointOfIssue::where('branch_id', $b)->firstOrFail();
        $this->assertEqualsCanonicalizing([$d1], $point->cashDrawers()->pluck('cash_drawers.id')->all());

        // A caja created after SAR was enabled.
        $d2 = $this->drawer($b, $l, 'Caja 2');
        $this->sync->syncCashDrawer(CashDrawer::findOrFail($d2));

        $this->assertEqualsCanonicalizing(
            [$d1, $d2],
            $point->fresh()->cashDrawers()->pluck('cash_drawers.id')->all()
        );
    }

    public function test_4_repeated_sync_never_duplicates_points_or_pivot_rows(): void
    {
        $this->profile();
        $b = $this->branch('Sucursal Principal');
        $l = $this->location($b);
        $this->drawer($b, $l, 'Caja 1');
        $this->drawer($b, $l, 'Caja 2');

        $branch = Branch::findOrFail($b);
        $this->sync->setBranchEnabled($b, true);
        $this->sync->syncBranch($branch);
        $this->sync->syncBranch($branch);
        $this->sync->syncAllActiveBranches();

        $this->assertSame(1, SarPointOfIssue::where('branch_id', $b)->count());
        $this->assertSame(2, DB::table('sar_point_cash_drawers')->count());
        $this->assertSame(1, SarBranchSetting::where('branch_id', $b)->count());
    }

    public function test_5_disabling_a_branch_blocks_invoicing_from_it(): void
    {
        $this->profile();
        $b = $this->branch('Sucursal Principal');
        $l = $this->location($b);
        $d = $this->drawer($b, $l, 'Caja 1');
        $this->configureBranch($b, '001', '001', ['cai' => 'CAI-A', 'range_start' => 1, 'range_end' => 50, 'next_number' => 1]);

        // Works while enabled.
        $doc = $this->sales->issueIfEnabled($this->sale($b, $l, $d), $d);
        $this->assertSame('CAI-A', $doc->cai);

        // Disable fiscally -> POS is refused for that branch.
        $this->sync->setBranchEnabled($b, false);

        $this->expectException(SarFiscalException::class);
        $this->expectExceptionMessage('no está habilitada para');
        $this->sales->issueIfEnabled($this->sale($b, $l, $d), $d);
    }

    public function test_6_branch_a_never_consumes_branch_b_cai(): void
    {
        $this->profile();

        $a = $this->branch('Sucursal A');
        $la = $this->location($a);
        $da = $this->drawer($a, $la, 'Caja A');
        $this->configureBranch($a, '001', '001', ['cai' => 'CAI-A', 'range_start' => 1000, 'range_end' => 1999, 'next_number' => 1000]);

        $bb = $this->branch('Sucursal B');
        $lb = $this->location($bb);
        $db = $this->drawer($bb, $lb, 'Caja B');
        $this->configureBranch($bb, '002', '001', ['cai' => 'CAI-B', 'range_start' => 5000, 'range_end' => 5999, 'next_number' => 5000]);

        $docA = $this->sales->issueIfEnabled($this->sale($a, $la, $da), $da);
        $docB = $this->sales->issueIfEnabled($this->sale($bb, $lb, $db), $db);

        $this->assertSame('CAI-A', $docA->cai);
        $this->assertSame('001-001-01-00001000', $docA->fiscal_number);
        $this->assertSame('CAI-B', $docB->cai);
        $this->assertSame('002-001-01-00005000', $docB->fiscal_number);

        // A sale in branch A that somehow references branch B's drawer is rejected.
        $this->expectException(SarFiscalException::class);
        $this->expectExceptionMessage('pertenece a otra sucursal');
        $this->sales->issueIfEnabled($this->sale($a, $la, $db), $db);
    }

    public function test_7_existing_manual_point_is_adopted_and_keeps_working(): void
    {
        $this->profile();
        $b = $this->branch('Sucursal Heredada');
        $l = $this->location($b);
        $d = $this->drawer($b, $l, 'Caja Heredada');

        // A point created the old way: explicit codes + single cash_drawer_id.
        $legacyId = (int) DB::table('sar_points_of_issue')->insertGetId([
            'establishment_code' => '077', 'point_code' => '003', 'name' => 'Punto heredado', 'address' => 'Dir',
            'branch_id' => $b, 'inventory_location_id' => $l, 'cash_drawer_id' => $d,
            'active' => true, 'is_auto_managed' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sar_point_cash_drawers')->insert([
            'sar_point_of_issue_id' => $legacyId, 'cash_drawer_id' => $d, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sar_branch_settings')->insert([
            'branch_id' => $b, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sar_authorizations')->insert([
            'point_of_issue_id' => $legacyId, 'document_type' => '01', 'cai' => 'CAI-LEGACY',
            'range_start' => 1, 'range_end' => 10, 'next_number' => 1,
            'deadline' => now()->addYear()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The new sync adopts the existing point instead of creating a second one.
        $point = $this->sync->syncBranch(Branch::findOrFail($b));

        $this->assertSame($legacyId, $point->id, 'The legacy point is adopted, not duplicated.');
        $this->assertTrue((bool) $point->is_auto_managed);
        $this->assertSame('077', $point->establishment_code, 'Authorised codes are preserved.');
        $this->assertSame('003', $point->point_code);
        $this->assertSame(1, SarPointOfIssue::where('branch_id', $b)->count());

        // And it still invoices with the existing CAI.
        $doc = $this->sales->issueIfEnabled($this->sale($b, $l, $d), $d);
        $this->assertSame('CAI-LEGACY', $doc->cai);
        $this->assertSame('077-003-01-00000001', $doc->fiscal_number);
    }
}
