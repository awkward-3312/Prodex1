<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileCashRegisterController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MobileCashRegisterTestSchema;
use Tests\TestCase;

class MobileCashRegisterCurrentEndpointTest extends TestCase
{
    use MobileCashRegisterTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileCashRegisterSchema();
        Route::middleware('auth:api')->get('/api/mobile/cash-register-test/current', [MobileCashRegisterController::class, 'current']);
    }

    private function url(int $branchId, int $locationId): string
    {
        return "/api/mobile/cash-register-test/current?branch_id={$branchId}&inventory_location_id={$locationId}";
    }

    public function test_requires_authentication(): void
    {
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->getJson($this->url($branch->id, $location->id))->assertStatus(401);
    }

    public function test_denies_user_without_sales_pos_permission(): void
    {
        $user = $this->user(['role_id' => 2], []);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(403);
    }

    public function test_returns_closed_status_with_no_register_when_none_is_open(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);

        $response = $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(200);
        $this->assertSame(['status' => 'closed', 'register' => null, 'summary' => null], $response->json('data'));
    }

    public function test_returns_only_the_authenticated_users_own_open_register(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $otherUser = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->cashRegister(['user_id' => $otherUser->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'opening_balance' => 999]);

        $response = $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(200);
        $this->assertSame('closed', $response->json('data.status'));
    }

    public function test_open_register_returns_full_authoritative_summary(): void
    {
        $user = $this->user(['role_id' => 2, 'firstname' => 'Cajero', 'lastname' => 'Uno'], ['Pos_view']);
        $branch = $this->branch(['name' => 'Sucursal Centro']);
        $location = $this->location($branch->id, ['name' => 'Piso de venta']);
        $cash = $this->paymentMethod('Efectivo');
        $card = $this->paymentMethod('Tarjeta');

        $register = $this->cashRegister([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id,
            'opening_balance' => 500, 'cash_in' => 200, 'cash_out' => 100,
        ]);

        DB::table('sales')->insert(['user_id' => $user->id, 'is_pos' => 1, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'GrandTotal' => 300, 'created_at' => now(), 'updated_at' => now()]);
        $saleId1 = DB::getPdo()->lastInsertId();
        DB::table('sales')->insert(['user_id' => $user->id, 'is_pos' => 1, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'GrandTotal' => 150, 'created_at' => now(), 'updated_at' => now()]);
        $saleId2 = DB::getPdo()->lastInsertId();
        $this->paymentSale((int) $saleId1, $cash->id, 300);
        $this->paymentSale((int) $saleId2, $card->id, 150);

        $response = $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame('open', $data['status']);
        $this->assertSame($register->id, $data['register']['id']);
        $this->assertSame('500.00', $data['register']['opening_balance']);
        $this->assertSame('Sucursal Centro', $data['register']['branch']['name']);
        $this->assertSame('Piso de venta', $data['register']['inventory_location']['name']);

        $this->assertSame(2, $data['summary']['transaction_count']);
        $this->assertSame('450.00', $data['summary']['total_sales']);
        $this->assertSame('300.00', $data['summary']['cash_sales']);
        $this->assertSame('200.00', $data['summary']['cash_in']);
        $this->assertSame('100.00', $data['summary']['cash_out']);
        $this->assertSame('0.00', $data['summary']['cash_refunds']);
        // expected_cash = opening(500) + cash_sales(300) + cash_in(200) - cash_out(100) - refunds(0) = 900.00
        $this->assertSame('900.00', $data['summary']['expected_cash']);
        $this->assertSame('150.00', $data['summary']['card_system_total']);

        $methods = collect($data['summary']['sales_by_payment_method'])->keyBy('name');
        $this->assertSame('300.00', $methods['Efectivo']['total']);
        $this->assertSame('cash', $methods['Efectivo']['category']);
        $this->assertSame('150.00', $methods['Tarjeta']['total']);
        $this->assertSame('card', $methods['Tarjeta']['category']);
    }

    public function test_cash_refunds_reduce_expected_cash(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $cash = $this->paymentMethod('Efectivo');
        $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'opening_balance' => 100]);

        DB::table('sales')->insert(['user_id' => $user->id, 'is_pos' => 1, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'GrandTotal' => 200, 'created_at' => now(), 'updated_at' => now()]);
        $saleId = DB::getPdo()->lastInsertId();
        $this->paymentSale((int) $saleId, $cash->id, 200);

        $returnId = DB::table('sale_returns')->insertGetId(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'GrandTotal' => 50, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_sale_returns')->insert(['sale_return_id' => $returnId, 'payment_method_id' => $cash->id, 'montant' => 50, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame('50.00', $data['summary']['cash_refunds']);
        // expected_cash = 100 + 200 + 0 - 0 - 50 = 250.00
        $this->assertSame('250.00', $data['summary']['expected_cash']);
    }

    public function test_endpoint_never_mutates_the_register_or_sales(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id]);

        $before = (array) DB::table('cash_registers')->where('id', $register->id)->first();
        $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(200);
        $this->actingAs($user, 'api')->getJson($this->url($branch->id, $location->id))->assertStatus(200);
        $after = (array) DB::table('cash_registers')->where('id', $register->id)->first();

        $this->assertSame($before, $after);
    }
}
