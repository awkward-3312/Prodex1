<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileCashRegisterHistoryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MobileCashRegisterTestSchema;
use Tests\TestCase;

class MobileCashRegisterHistoryEndpointTest extends TestCase
{
    use MobileCashRegisterTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileCashRegisterSchema();
        Route::middleware('auth:api')->get('/api/mobile/cash-register-test/history', [MobileCashRegisterHistoryController::class, 'history']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/mobile/cash-register-test/history')->assertStatus(401);
    }

    public function test_denies_user_without_cash_register_report_permission(): void
    {
        $user = $this->user(['role_id' => 2], []);
        $this->actingAs($user, 'api')->getJson('/api/mobile/cash-register-test/history')->assertStatus(403);
    }

    public function test_allows_user_with_cash_register_report_permission(): void
    {
        $user = $this->user(['role_id' => 1], ['cash_register_report']);
        $this->actingAs($user, 'api')->getJson('/api/mobile/cash-register-test/history')->assertStatus(200);
    }

    public function test_lists_closed_sessions_with_authoritative_snapshot_fields(): void
    {
        $owner = $this->user(['role_id' => 1], ['cash_register_report']);
        $cashier = $this->user(['role_id' => 2, 'firstname' => 'Ana', 'lastname' => 'Lopez'], []);
        $branch = $this->branch(['name' => 'Sucursal Norte']);
        $location = $this->location($branch->id, ['name' => 'Mostrador']);

        $this->cashRegister([
            'user_id' => $cashier->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id,
            'status' => 'closed', 'opened_at' => now()->subHours(8), 'closed_at' => now(),
            'opening_balance' => 500, 'total_sales' => 1200, 'expected_cash' => 700, 'counted_cash' => 700, 'cash_difference' => 0,
            'closing_status' => 'balanced', 'branch_name_snapshot' => 'Sucursal Norte', 'inventory_location_name_snapshot' => 'Mostrador',
            'opened_by_user_name_snapshot' => 'Ana Lopez',
        ]);

        $response = $this->actingAs($owner, 'api')->getJson('/api/mobile/cash-register-test/history')->assertStatus(200);
        $item = $response->json('data.items.0');

        $this->assertSame('closed', $item['status']);
        $this->assertSame('balanced', $item['closing_status']);
        $this->assertSame('Ana Lopez', $item['user']['name']);
        $this->assertSame('Sucursal Norte', $item['branch']);
        $this->assertSame('Mostrador', $item['inventory_location']);
        $this->assertSame('500.00', $item['opening_balance']);
        $this->assertSame('1200.00', $item['total_sales']);
        $this->assertSame('700.00', $item['expected_cash']);
        $this->assertSame('700.00', $item['counted_cash']);
        $this->assertSame('0.00', $item['difference']);
    }

    public function test_only_returns_closed_sessions_never_the_currently_open_one(): void
    {
        $owner = $this->user(['role_id' => 1], ['cash_register_report']);
        $this->cashRegister(['user_id' => $owner->id, 'status' => 'open']);
        $this->cashRegister(['user_id' => $owner->id, 'status' => 'closed', 'closed_at' => now()]);

        $response = $this->actingAs($owner, 'api')->getJson('/api/mobile/cash-register-test/history')->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_filters_by_date_range(): void
    {
        $owner = $this->user(['role_id' => 1], ['cash_register_report']);
        $this->cashRegister(['user_id' => $owner->id, 'status' => 'closed', 'closed_at' => '2026-05-10 10:00:00']);
        $this->cashRegister(['user_id' => $owner->id, 'status' => 'closed', 'closed_at' => '2026-01-01 10:00:00']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/cash-register-test/history?from=2026-05-01&to=2026-05-31')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_paginates_results(): void
    {
        $owner = $this->user(['role_id' => 1], ['cash_register_report']);
        for ($i = 0; $i < 3; $i++) {
            $this->cashRegister(['user_id' => $owner->id, 'status' => 'closed', 'closed_at' => now()]);
        }

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/cash-register-test/history?per_page=2&page=1')
            ->assertStatus(200);

        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertTrue($response->json('data.pagination.has_more'));
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_a_user_without_record_view_only_sees_their_own_closed_sessions(): void
    {
        $owner = $this->user(['role_id' => 1], []);
        $cashier = $this->user(['role_id' => 2, 'record_view' => false], ['cash_register_report']);

        $this->cashRegister(['user_id' => $cashier->id, 'status' => 'closed', 'closed_at' => now(), 'register_number_snapshot' => 'OWN-SESSION']);
        $this->cashRegister(['user_id' => $owner->id, 'status' => 'closed', 'closed_at' => now(), 'register_number_snapshot' => 'OTHER-SESSION']);

        $response = $this->actingAs($cashier, 'api')->getJson('/api/mobile/cash-register-test/history')->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_endpoint_never_mutates_any_register(): void
    {
        $owner = $this->user(['role_id' => 1], ['cash_register_report']);
        $register = $this->cashRegister(['user_id' => $owner->id, 'status' => 'closed', 'closed_at' => now()]);
        $before = (array) DB::table('cash_registers')->where('id', $register->id)->first();

        $this->actingAs($owner, 'api')->getJson('/api/mobile/cash-register-test/history')->assertStatus(200);
        $after = (array) DB::table('cash_registers')->where('id', $register->id)->first();

        $this->assertSame($before, $after);
    }
}
