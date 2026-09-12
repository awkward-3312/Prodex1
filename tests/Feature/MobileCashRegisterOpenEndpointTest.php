<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileCashRegisterOpenController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\MobileCashRegisterTestSchema;
use Tests\TestCase;

class MobileCashRegisterOpenEndpointTest extends TestCase
{
    use MobileCashRegisterTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileCashRegisterSchema();
        Route::middleware('auth:api')->post('/api/mobile/cash-register-test/open', MobileCashRegisterOpenController::class);
    }

    private function url(): string
    {
        return '/api/mobile/cash-register-test/open';
    }

    public function test_requires_authentication(): void
    {
        $this->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'opening_balance' => '500.00'])
            ->assertStatus(401);
    }

    public function test_denies_user_without_sales_pos_permission(): void
    {
        $user = $this->user(['role_id' => 2], []);
        $this->actingAs($user, 'api')
            ->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'opening_balance' => '500.00'])
            ->assertStatus(403);
    }

    public function test_opens_register_with_exact_opening_balance_and_derived_assignment(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);

        $response = $this->actingAs($user, 'api')
            ->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'opening_balance' => '500.00', 'notes' => 'Apertura normal'])
            ->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertFalse($response->json('idempotent'));
        $this->assertSame('500.00', $response->json('register.opening_balance'));

        $register = DB::table('cash_registers')->first();
        $this->assertSame($user->id, $register->user_id);
        $this->assertSame($branch->id, $register->branch_id);
        $this->assertSame($location->id, $register->inventory_location_id);
        $this->assertSame('Apertura normal', $register->notes);
        $this->assertSame('open', $register->status);
    }

    public function test_rejects_arbitrary_branch_id_and_user_id_from_payload(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $otherBranch = $this->branch();

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(),
            'opening_balance' => '500.00',
            'branch_id' => $otherBranch->id,
            'user_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_already_open_register_returns_409(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id]);

        $this->actingAs($user, 'api')
            ->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'opening_balance' => '500.00'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'register_already_open');

        $this->assertSame(1, DB::table('cash_registers')->count());
    }

    public function test_same_operation_uuid_retry_returns_idempotent_success_for_same_register(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $uuid = Str::uuid()->toString();
        $payload = ['operation_uuid' => $uuid, 'opening_balance' => '500.00', 'notes' => 'Apertura'];

        $first = $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);
        $this->assertFalse($first->json('idempotent'));
        $registerId = $first->json('register.id');

        $second = $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);
        $this->assertTrue($second->json('idempotent'));
        $this->assertSame($registerId, $second->json('register.id'));

        $this->assertSame(1, DB::table('cash_registers')->count());
        $this->assertSame(1, DB::table('cash_register_operations')->count());
    }

    public function test_same_operation_uuid_with_different_payload_returns_409_idempotency_conflict(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $uuid = Str::uuid()->toString();

        $this->actingAs($user, 'api')
            ->postJson($this->url(), ['operation_uuid' => $uuid, 'opening_balance' => '500.00'])
            ->assertStatus(200);

        $this->actingAs($user, 'api')
            ->postJson($this->url(), ['operation_uuid' => $uuid, 'opening_balance' => '999.00'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, DB::table('cash_registers')->count());
    }

    public function test_tenant_isolation_does_not_see_another_tenant_register(): void
    {
        $userA = $this->user(['role_id' => 2], ['Pos_view']);
        $branchA = $this->branch();
        $locationA = $this->location($branchA->id);
        $this->assignOperationalContext($userA, $branchA->id, $locationA->id);

        $userB = $this->user(['role_id' => 2], ['Pos_view']);
        $branchB = $this->branch();
        $locationB = $this->location($branchB->id);
        $this->assignOperationalContext($userB, $branchB->id, $locationB->id);

        $this->actingAs($userA, 'api')
            ->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'opening_balance' => '500.00'])
            ->assertStatus(200);

        $this->actingAs($userB, 'api')
            ->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'opening_balance' => '100.00'])
            ->assertStatus(200);

        $this->assertSame(2, DB::table('cash_registers')->count());
        $registerB = DB::table('cash_registers')->where('user_id', $userB->id)->first();
        $this->assertSame($branchB->id, $registerB->branch_id);
    }
}
