<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileCashRegisterMovementController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\MobileCashRegisterTestSchema;
use Tests\TestCase;

class MobileCashRegisterMovementEndpointTest extends TestCase
{
    use MobileCashRegisterTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileCashRegisterSchema();
        Route::middleware('auth:api')->post('/api/mobile/cash-register-test/movements', MobileCashRegisterMovementController::class);
    }

    private function url(): string
    {
        return '/api/mobile/cash-register-test/movements';
    }

    private function openRegisterFor($user, $branch, $location): object
    {
        $this->assignOperationalContext($user, $branch->id, $location->id);
        return $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id]);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson($this->url(), ['operation_uuid' => Str::uuid()->toString(), 'register_id' => 1, 'type' => 'in', 'amount' => '50.00', 'notes' => 'Cambio'])
            ->assertStatus(401);
    }

    public function test_denies_user_without_sales_pos_permission(): void
    {
        $user = $this->user(['role_id' => 2], []);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '50.00', 'notes' => 'Cambio',
        ])->assertStatus(403);
    }

    public function test_cash_in_applies_exact_amount(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);

        $response = $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '75.50', 'notes' => 'Cambio inicial',
        ])->assertStatus(200);

        $this->assertFalse($response->json('idempotent'));
        $row = DB::table('cash_registers')->where('id', $register->id)->first();
        $this->assertEquals(75.50, (float) $row->cash_in);
    }

    public function test_cash_out_applies_exact_amount(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'out', 'amount' => '30.00', 'notes' => 'Compra de insumos',
        ])->assertStatus(200);

        $row = DB::table('cash_registers')->where('id', $register->id)->first();
        $this->assertEquals(30.00, (float) $row->cash_out);
    }

    public function test_notes_required_for_movement(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '50.00',
        ])->assertStatus(422);
    }

    public function test_amount_zero_is_rejected(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '0', 'notes' => 'Cambio',
        ])->assertStatus(422);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '-10.00', 'notes' => 'Cambio',
        ])->assertStatus(422);
    }

    public function test_closed_register_is_rejected(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $register = $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'status' => 'closed']);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '50.00', 'notes' => 'Cambio',
        ])->assertStatus(409)->assertJsonPath('error.code', 'register_closed');
    }

    public function test_foreign_register_is_rejected(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $owner = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $register = $this->cashRegister(['user_id' => $owner->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id]);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '50.00', 'notes' => 'Cambio',
        ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

        $row = DB::table('cash_registers')->where('id', $register->id)->first();
        $this->assertEquals(0, (float) $row->cash_in);
    }

    public function test_same_operation_uuid_same_payload_applies_exactly_once(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);
        $uuid = Str::uuid()->toString();
        $payload = ['operation_uuid' => $uuid, 'register_id' => $register->id, 'type' => 'in', 'amount' => '40.00', 'notes' => 'Reposicion de caja'];

        $first = $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);
        $this->assertFalse($first->json('idempotent'));

        $second = $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);
        $this->assertTrue($second->json('idempotent'));

        $row = DB::table('cash_registers')->where('id', $register->id)->first();
        $this->assertEquals(40.00, (float) $row->cash_in);
        $this->assertSame(1, DB::table('cash_register_operations')->count());
    }

    public function test_same_operation_uuid_different_payload_returns_409_and_does_not_apply_twice(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);
        $uuid = Str::uuid()->toString();

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => $uuid, 'register_id' => $register->id, 'type' => 'in', 'amount' => '40.00', 'notes' => 'Reposicion de caja',
        ])->assertStatus(200);

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => $uuid, 'register_id' => $register->id, 'type' => 'in', 'amount' => '99.00', 'notes' => 'Otro motivo',
        ])->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

        $row = DB::table('cash_registers')->where('id', $register->id)->first();
        $this->assertEquals(40.00, (float) $row->cash_in);
    }

    public function test_retry_after_lost_response_applies_amount_exactly_once(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);
        $uuid = Str::uuid()->toString();
        $payload = ['operation_uuid' => $uuid, 'register_id' => $register->id, 'type' => 'out', 'amount' => '25.00', 'notes' => 'Retiro parcial'];

        // Simulates the client never having seen the first (successful) response
        // and retrying blind with the identical frozen payload + UUID.
        $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);
        $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);
        $this->actingAs($user, 'api')->postJson($this->url(), $payload)->assertStatus(200);

        $row = DB::table('cash_registers')->where('id', $register->id)->first();
        $this->assertEquals(25.00, (float) $row->cash_out);
    }

    public function test_summary_and_expected_cash_reflect_the_movement(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $register = $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'opening_balance' => 100]);

        $response = $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'type' => 'in', 'amount' => '50.00', 'notes' => 'Cambio',
        ])->assertStatus(200);

        $this->assertSame('50.00', $response->json('summary.cash_in'));
        // expected_cash = opening(100) + sales(0) + cash_in(50) - cash_out(0) - refunds(0) = 150.00
        $this->assertSame('150.00', $response->json('summary.expected_cash'));
    }

    public function test_audit_record_is_created_for_the_movement(): void
    {
        $user = $this->user(['role_id' => 2], ['Pos_view']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $register = $this->openRegisterFor($user, $branch, $location);
        $uuid = Str::uuid()->toString();

        $this->actingAs($user, 'api')->postJson($this->url(), [
            'operation_uuid' => $uuid, 'register_id' => $register->id, 'type' => 'in', 'amount' => '40.00', 'notes' => 'Reposicion de caja',
        ])->assertStatus(200);

        $op = DB::table('cash_register_operations')->where('operation_uuid', $uuid)->first();
        $this->assertNotNull($op);
        $this->assertSame('cash_in', $op->operation_type);
        $this->assertSame($user->id, $op->user_id);
        $this->assertSame($register->id, $op->cash_register_id);
        $this->assertEquals(40.00, (float) $op->amount);
        $this->assertSame('mobile', $op->source);
    }

    public function test_tenant_isolation_movement_does_not_affect_other_tenant_register(): void
    {
        $userA = $this->user(['role_id' => 2], ['Pos_view']);
        $branchA = $this->branch();
        $locationA = $this->location($branchA->id);
        $registerA = $this->openRegisterFor($userA, $branchA, $locationA);

        $userB = $this->user(['role_id' => 2], ['Pos_view']);
        $branchB = $this->branch();
        $locationB = $this->location($branchB->id);
        $registerB = $this->openRegisterFor($userB, $branchB, $locationB);

        $this->actingAs($userA, 'api')->postJson($this->url(), [
            'operation_uuid' => Str::uuid()->toString(), 'register_id' => $registerA->id, 'type' => 'in', 'amount' => '40.00', 'notes' => 'Cambio A',
        ])->assertStatus(200);

        $rowB = DB::table('cash_registers')->where('id', $registerB->id)->first();
        $this->assertEquals(0, (float) $rowB->cash_in);
    }
}
