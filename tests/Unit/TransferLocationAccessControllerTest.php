<?php

namespace Tests\Unit;

use App\Http\Controllers\FinalTransferLogisticsController;
use App\Models\Transfer;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TransferLocationAccessControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->string('Ref')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('from_warehouse_id')->nullable();
            $table->integer('to_warehouse_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('items', 12, 3)->default(0);
            $table->string('approval_status')->nullable();
            $table->string('statut')->nullable();
            $table->string('logistics_status')->nullable();
            $table->string('receiving_token')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
    }

    public function test_incoming_does_not_leak_modern_transfer_from_same_legacy_warehouse(): void
    {
        $this->transfer(100, 30, 10, 1, 1, 'TR-A');
        $this->transfer(101, 30, 20, 1, 1, 'TR-B');
        $this->transfer(102, null, null, 2, 1, 'TR-LEGACY');

        $user = $this->userMock();
        $logistics = Mockery::mock(TransferLogisticsService::class);
        $logistics->shouldReceive('warehouseIdsForUser')->with($user)->andReturn([1]);
        $logistics->shouldReceive('userCanReceive')->andReturnUsing(function ($candidate, Transfer $transfer) {
            return $transfer->to_inventory_location_id
                ? (int) $transfer->to_inventory_location_id === 10
                : (int) $transfer->to_warehouse_id === 1;
        });
        $this->app->instance(TransferLogisticsService::class, $logistics);

        $locations = Mockery::mock(InventoryLocationScopeService::class);
        $locations->shouldReceive('allowedLocationIds')->with($user)->andReturn([10]);
        $this->app->instance(InventoryLocationScopeService::class, $locations);

        $controller = new FinalTransferLogisticsController($logistics);
        $response = $controller->incoming($this->requestFor($user));
        $payload = $response->getData(true);

        $references = collect($payload['transfers'])->pluck('reference')->sort()->values()->all();
        $this->assertSame(['TR-A', 'TR-LEGACY'], $references);
        $this->assertNotContains('TR-B', $references);
        $this->assertSame('Sucursal A', collect($payload['transfers'])->firstWhere('reference', 'TR-A')['to_warehouse']);
    }

    public function test_qr_does_not_fall_back_to_warehouse_access_for_modern_transfer(): void
    {
        $this->transfer(200, 30, 20, 1, 1, 'TR-QR', 'TRF-QR-200');

        $user = $this->userMock();
        $logistics = Mockery::mock(TransferLogisticsService::class);
        $logistics->shouldReceive('warehouseIdsForUser')->andReturn([1]);
        $this->app->instance(TransferLogisticsService::class, $logistics);

        $locations = Mockery::mock(InventoryLocationScopeService::class);
        $locations->shouldReceive('canAccess')->with($user, 30)->andReturn(false);
        $locations->shouldReceive('canAccess')->with($user, 20)->andReturn(false);
        $this->app->instance(InventoryLocationScopeService::class, $locations);

        $controller = new FinalTransferLogisticsController($logistics);

        try {
            $controller->qrPayload($this->requestFor($user), 200);
            $this->fail('Expected a 403 for a modern transfer outside the physical location scope.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_qr_is_available_to_user_with_physical_source_access(): void
    {
        $this->transfer(201, 30, 20, 1, 1, 'TR-QR-OK', 'TRF-QR-201');

        $user = $this->userMock();
        $logistics = Mockery::mock(TransferLogisticsService::class);
        $this->app->instance(TransferLogisticsService::class, $logistics);

        $locations = Mockery::mock(InventoryLocationScopeService::class);
        $locations->shouldReceive('canAccess')->with($user, 30)->andReturn(true);
        $this->app->instance(InventoryLocationScopeService::class, $locations);

        $controller = new FinalTransferLogisticsController($logistics);
        $payload = $controller->qrPayload($this->requestFor($user), 201)->getData(true);

        $this->assertSame('TRF-QR-201', $payload['token']);
        $this->assertStringContainsString('/transfer-receive/TRF-QR-201', $payload['qr_value']);
    }

    private function transfer(
        int $id,
        ?int $fromLocation,
        ?int $toLocation,
        int $fromWarehouse,
        int $toWarehouse,
        string $reference,
        ?string $token = null
    ): void {
        DB::table('transfers')->insert([
            'id' => $id,
            'Ref' => $reference,
            'from_warehouse_id' => $fromWarehouse,
            'to_warehouse_id' => $toWarehouse,
            'from_inventory_location_id' => $fromLocation,
            'to_inventory_location_id' => $toLocation,
            'items' => 1,
            'approval_status' => 'approved',
            'statut' => 'sent',
            'logistics_status' => 'in_transit',
            'receiving_token' => $token ?: 'TRF-'.$id,
            'dispatched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function userMock(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 77;
        $user->role_id = 2;
        $user->is_all_warehouses = 0;
        $user->shouldReceive('hasPermissionName')
            ->with(TransferLogisticsService::RECEIVE_PERMISSION)
            ->andReturn(true);
        return $user;
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/api/transfer-logistics/incoming', 'GET');
        $request->setUserResolver(fn () => $user);
        return $request;
    }
}
