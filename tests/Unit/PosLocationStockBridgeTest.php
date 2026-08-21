<?php

namespace Tests\Unit;

use App\Services\PosLocationStockBridge;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class PosLocationStockBridgeTest extends TestCase
{
    public function test_explicit_branch_location_create_pos_request_enables_bridge(): void
    {
        $request = Request::create('/api/pos/create_pos', 'POST', [
            'branch_id' => 10,
            'inventory_location_id' => 20,
        ]);

        $route = new Route(['POST'], 'pos/create_pos', ['uses' => 'App\\Http\\Controllers\\PosController@CreatePOS']);
        $request->setRouteResolver(fn () => $route);

        $this->assertTrue(app(PosLocationStockBridge::class)->isLocationPosRequest($request));
    }

    public function test_unrelated_request_never_enables_bridge_even_with_location_ids(): void
    {
        $request = Request::create('/api/adjustments', 'POST', [
            'branch_id' => 10,
            'inventory_location_id' => 20,
        ]);

        $route = new Route(['POST'], 'adjustments', ['uses' => 'App\\Http\\Controllers\\AdjustmentController@store']);
        $request->setRouteResolver(fn () => $route);

        $this->assertFalse(app(PosLocationStockBridge::class)->isLocationPosRequest($request));
    }
}
