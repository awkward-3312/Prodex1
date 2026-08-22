<?php

namespace Tests\Unit;

use App\Http\Middleware\EnforceWarehouseScope;
use App\Models\Transfer;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\WarehouseScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class TransferWarehouseScopeMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->integer('from_warehouse_id')->nullable();
            $table->integer('to_warehouse_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_bulk_delete_rejects_any_modern_transfer_outside_source_location_scope(): void
    {
        $this->transfer(1, 10, 30, 1, 1);
        $this->transfer(2, 20, 30, 1, 1);

        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $locationScope = Mockery::mock(InventoryLocationScopeService::class);
        $locationScope->shouldReceive('canAccess')->with($user, 10)->andReturn(true);
        $locationScope->shouldReceive('canAccess')->with($user, 30)->andReturn(false)->zeroOrMoreTimes();
        $locationScope->shouldReceive('canAccess')->with($user, 20)->andReturn(false);

        $request = $this->request('POST', '/api/transfers/delete/by_selection', [
            'selectedIds' => [1, 2],
        ], 'App\Http\Controllers\TransferController@delete_by_selection');

        $this->expectException(AuthorizationException::class);
        $this->invokeValidation($request, $user, $warehouseScope, $locationScope);
    }

    public function test_bulk_delete_allows_modern_transfer_when_source_location_is_authorized(): void
    {
        $this->transfer(3, 10, 30, 1, 1);

        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $locationScope = Mockery::mock(InventoryLocationScopeService::class);
        $locationScope->shouldReceive('canAccess')->with($user, 10)->andReturn(true);
        $locationScope->shouldReceive('canAccess')->with($user, 30)->andReturn(false);

        $request = $this->request('POST', '/api/transfers/delete/by_selection', [
            'selectedIds' => [3],
        ], 'App\Http\Controllers\TransferController@delete_by_selection');

        $this->invokeValidation($request, $user, $warehouseScope, $locationScope);
        $this->addToAssertionCount(1);
    }

    public function test_transfer_pdf_is_readable_from_destination_scope_but_not_unrelated_scope(): void
    {
        $this->transfer(4, 20, 10, 1, 1);

        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $locationScope = Mockery::mock(InventoryLocationScopeService::class);
        $locationScope->shouldReceive('canAccess')->with($user, 20)->andReturn(false);
        $locationScope->shouldReceive('canAccess')->with($user, 10)->andReturn(true);

        $request = $this->request(
            'GET',
            '/api/transfer_pdf/4',
            [],
            'App\Http\Controllers\TransferController@transfer_pdf',
            ['id' => 4]
        );

        $this->invokeValidation($request, $user, $warehouseScope, $locationScope);
        $this->addToAssertionCount(1);
    }

    private function invokeValidation(
        Request $request,
        User $user,
        WarehouseScopeService $warehouseScope,
        InventoryLocationScopeService $locationScope
    ): void {
        $method = new ReflectionMethod(EnforceWarehouseScope::class, 'validateTransferRoute');
        $method->setAccessible(true);
        $method->invoke(
            app(EnforceWarehouseScope::class),
            $request,
            $user,
            $warehouseScope,
            $locationScope
        );
    }

    private function request(string $method, string $uri, array $data, string $action, array $parameters = []): Request
    {
        $request = Request::create($uri, $method, $data);
        $route = new Route([$method], ltrim($uri, '/'), ['uses' => $action]);
        foreach ($parameters as $key => $value) {
            $route->setParameter($key, $value);
        }
        $request->setRouteResolver(fn () => $route);
        return $request;
    }

    private function user(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 77;
        $user->is_all_warehouses = 0;
        return $user;
    }

    private function transfer(int $id, ?int $fromLocation, ?int $toLocation, ?int $fromWarehouse, ?int $toWarehouse): void
    {
        DB::table('transfers')->insert([
            'id' => $id,
            'from_inventory_location_id' => $fromLocation,
            'to_inventory_location_id' => $toLocation,
            'from_warehouse_id' => $fromWarehouse,
            'to_warehouse_id' => $toWarehouse,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }
}
