<?php

namespace Tests\Unit;

use App\Http\Controllers\FinalTransferDiscrepancyController;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TransferDiscrepancyLocationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            if (method_exists($pdo, 'sqliteCreateFunction')) {
                $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
            }
        }

        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('product_variants', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->string('Ref');
            $table->integer('from_warehouse_id')->nullable();
            $table->integer('to_warehouse_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('transfer_details', function ($table) {
            $table->increments('id');
            $table->integer('transfer_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->timestamps();
        });
        Schema::create('transfer_discrepancies', function ($table) {
            $table->increments('id');
            $table->integer('transfer_id');
            $table->integer('transfer_detail_id');
            $table->integer('warehouse_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 12, 3);
            $table->string('resolution_status')->default('open');
            $table->string('resolution_code')->nullable();
            $table->string('resolution_reference')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->text('notes')->nullable();
            $table->integer('reported_by_user_id')->nullable();
            $table->integer('resolved_by_user_id')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'CD compartido', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Otro CD', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('inventory_locations')->insert([
            ['id' => 10, 'name' => 'Sucursal A', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'name' => 'Sucursal B', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 30, 'name' => 'Origen', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('products')->insert(['id' => 1, 'name' => 'Producto', 'code' => 'P-1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_receiver_sees_only_destination_location_issues_plus_legacy_scope(): void
    {
        $this->issue(100, 1000, 30, 10, 1, 1, 'ISS-A');
        $this->issue(101, 1001, 30, 20, 1, 1, 'ISS-B');
        $this->issue(102, 1002, null, null, 2, 1, 'ISS-LEGACY');

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('hasPermissionName')->with(TransferLogisticsService::RECEIVE_PERMISSION)->andReturn(true);
        $user->shouldReceive('hasPermissionName')->with(FinalTransferDiscrepancyController::MANAGE_PERMISSION)->andReturn(false);

        $logistics = Mockery::mock(TransferLogisticsService::class);
        $logistics->shouldReceive('warehouseIdsForUser')->with($user)->andReturn([1]);
        $this->app->instance(TransferLogisticsService::class, $logistics);

        $locations = Mockery::mock(InventoryLocationScopeService::class);
        $locations->shouldReceive('allowedLocationIds')->with($user)->andReturn([10]);
        $this->app->instance(InventoryLocationScopeService::class, $locations);

        $request = Request::create('/api/transfer-logistics/issues', 'GET');
        $request->setUserResolver(fn ($guard = null) => $user);

        $controller = new FinalTransferDiscrepancyController($logistics);
        $payload = $controller->index($request)->getData(true);
        $references = collect($payload['issues'])->pluck('reference')->sort()->values()->all();

        $this->assertSame(['ISS-A', 'ISS-LEGACY'], $references);
        $this->assertNotContains('ISS-B', $references);
        $this->assertSame('Sucursal A', collect($payload['issues'])->firstWhere('reference', 'ISS-A')['to_warehouse']);
    }

    private function issue(
        int $transferId,
        int $detailId,
        ?int $fromLocation,
        ?int $toLocation,
        int $fromWarehouse,
        int $toWarehouse,
        string $reference
    ): void {
        DB::table('transfers')->insert([
            'id' => $transferId,
            'Ref' => $reference,
            'from_warehouse_id' => $fromWarehouse,
            'to_warehouse_id' => $toWarehouse,
            'from_inventory_location_id' => $fromLocation,
            'to_inventory_location_id' => $toLocation,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);
        DB::table('transfer_details')->insert([
            'id' => $detailId,
            'transfer_id' => $transferId,
            'product_id' => 1,
            'product_variant_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transfer_discrepancies')->insert([
            'transfer_id' => $transferId,
            'transfer_detail_id' => $detailId,
            'warehouse_id' => $toWarehouse,
            'type' => 'missing',
            'quantity' => 1,
            'resolution_status' => 'open',
            'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
