<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileCashRegisterCloseController;
use App\Http\Controllers\PosCashRegisterController;
use App\Models\CashRegister;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\MobileCashRegisterTestSchema;
use Tests\TestCase;

class MobileCashRegisterCloseEndpointTest extends TestCase
{
    use MobileCashRegisterTestSchema;
    private string $url = '/api/mobile/cash-register-test/close';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileCashRegisterSchema();
        Route::middleware('auth:api')->post($this->url, MobileCashRegisterCloseController::class);
    }

    private function setupClose(): array
    {
        $user = $this->user([], ['Pos_view', 'cash_register_report']);
        $branch = $this->branch();
        $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $register = $this->cashRegister(['user_id' => $user->id, 'branch_id' => $branch->id, 'inventory_location_id' => $location->id, 'opening_balance' => 100, 'opened_at' => now()->subHour()]);
        $payload = ['operation_uuid' => Str::uuid()->toString(), 'register_id' => $register->id, 'counted_cash' => '1025.00'];
        return [$user, $register, $payload];
    }

    public function test_auth_and_permission(): void
    {
        $this->postJson($this->url, [])->assertStatus(401);
        [, , $payload] = $this->setupClose();
        $user = $this->user([], []);
        $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertStatus(403);
        $this->assertSame(0, DB::table('cash_register_operations')->count());
    }

    public function test_authoritative_close_and_frozen_retry(): void
    {
        [$user, $register, $payload] = $this->setupClose();
        $model = CashRegister::find($register->id);
        foreach (['Cash', 'Credit Card'] as $method) {
            $this->sale(['user_id' => $user->id, 'branch_id' => $model->branch_id, 'inventory_location_id' => $model->inventory_location_id, 'GrandTotal' => 920]);
            $this->paymentSale(DB::table('sales')->max('id'), $this->paymentMethod($method)->id, 920);
        }
        $payload += ['card_terminal_total' => '900.00', 'card_batch_number' => 'batch-1', 'card_reference' => 'reference', 'card_notes' => 'terminal', 'transfers_verified' => true, 'transfer_notes' => 'verificadas', 'cash_withdrawn_at_close' => '1000.00', 'next_opening_float' => '25.00', 'counted_denominations' => ['500' => 2, '5' => 5], 'notes' => 'Cierre revisado'];
        $first = $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertOk()->assertJsonPath('idempotent', false);
        $row = CashRegister::find($register->id);
        $this->assertSame('closed', $row->status);
        $this->assertNotNull($row->closed_at);
        $this->assertEquals(1020, $row->expected_cash);
        $this->assertEquals(5, $row->cash_difference);
        $this->assertEquals(1840, $row->total_sales);
        $this->assertEquals(-20, $row->card_difference);
        $this->assertSame('batch-1', $row->card_batch_number);
        $this->assertSame('reference', $row->card_reference);
        $this->assertTrue((bool) $row->transfers_verified);
        $this->assertSame('verificadas', $row->transfer_notes);
        $this->assertSame('over', $row->closing_status);
        $this->assertNotNull($row->branch_name_snapshot);
        $this->assertNotNull($row->inventory_location_name_snapshot);
        $this->assertEquals($user->id, $row->closed_by_user_id);
        $this->assertGreaterThanOrEqual(3600, $row->session_duration_seconds);
        $this->assertEquals(2, $row->closing_snapshot['transaction_count']);
        $snapshot = $row->closing_snapshot;
        $closedAt = $row->closed_at->toIso8601String();
        Carbon::setTestNow(now()->addHours(2));
        try {
            for ($i = 0; $i < 3; $i++) {
                $retry = $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('idempotent', true);
                $this->assertSame($first->json('summary'), $retry->json('summary'));
                $this->assertSame($closedAt, $retry->json('register.closed_at'));
            }
        } finally { Carbon::setTestNow(); }
        $this->assertSame($snapshot, CashRegister::find($register->id)->closing_snapshot);
        $this->assertSame(1, DB::table('cash_register_operations')->count());
        $this->assertSame('close', DB::table('cash_register_operations')->value('operation_type'));
        $this->assertEquals(1025, DB::table('cash_register_operations')->value('amount'));
        $payload['operation_uuid'] = Str::uuid()->toString();
        $this->postJson($this->url, $payload)->assertStatus(409)->assertJsonPath('error.code', 'register_already_closed');
    }

    public static function mismatches(): array { return [['user_id', 403], ['branch_id', 409], ['inventory_location_id', 409], ['cash_drawer_id', 409]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatches')]
    public function test_exact_owner_and_context(string $field, int $status): void
    {
        [$user, $register, $payload] = $this->setupClose();
        DB::table('cash_registers')->where('id', $register->id)->update([$field => 999]);
        $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertStatus($status);
        $this->assertSame('open', CashRegister::find($register->id)->status);
        $this->assertSame(0, DB::table('cash_register_operations')->count());
    }

    public static function invalidAmounts(): array { return [[10], ['1e3'], ['-1'], ['1.001'], ['1,00'], [''], ['10000000000.00']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmounts')]
    public function test_strict_decimal_validation($amount): void
    {
        [$user, , $payload] = $this->setupClose();
        $payload['counted_cash'] = $amount;
        $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(0, DB::table('cash_register_operations')->count());
    }

    public function test_uuid_conflict_and_canonical_decimals(): void
    {
        [$user, , $payload] = $this->setupClose();
        $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertOk();
        $payload['counted_cash'] = '1025';
        $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('idempotent', true);
        $payload['notes'] = 'different';
        $this->postJson($this->url, $payload)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, DB::table('cash_register_operations')->count());
    }

    public function test_foreign_owner_cannot_replay_saved_uuid(): void
    {
        [$user, , $payload] = $this->setupClose();
        $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertOk();
        $other = $this->user([], ['Pos_view']);
        $this->actingAs($other, 'api')->postJson($this->url, $payload)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_unknown_register_and_untrusted_identity(): void
    {
        [$user, , $payload] = $this->setupClose();
        $this->actingAs($user, 'api')->postJson($this->url, $payload + ['user_id' => $user->id])->assertStatus(422);
        $this->postJson($this->url, $payload + ['tenant_id' => 'other'])->assertStatus(422);
        $payload['register_id'] = 999;
        $this->postJson($this->url, $payload)->assertStatus(404)->assertJsonPath('error.code', 'register_not_found');
    }

    public function test_native_web_close_uses_same_engine_and_mobile_history_shows_frozen_close(): void
    {
        [$user, $register, $payload] = $this->setupClose();
        Route::middleware('auth:api')->post('/api/close-native-test', [PosCashRegisterController::class, 'closeRegister']);
        Route::middleware('auth:api')->get('/api/close-history-test', [\App\Http\Controllers\Mobile\MobileCashRegisterHistoryController::class, 'history']);
        $this->actingAs($user, 'api')->postJson('/api/close-native-test', $payload)->assertOk();
        $web = CashRegister::find($register->id);
        $this->assertSame('closed', $web->status);
        $this->assertNotNull($web->branch_name_snapshot);
        $this->assertNotNull($web->inventory_location_name_snapshot);
        $other = $this->cashRegister(['user_id' => $user->id, 'branch_id' => $web->branch_id, 'inventory_location_id' => $web->inventory_location_id, 'opening_balance' => 100]);
        $payload['register_id'] = $other->id;
        $this->postJson($this->url, $payload)->assertOk();
        $mobile = CashRegister::find($other->id);
        foreach (['expected_cash', 'counted_cash', 'cash_difference', 'card_system_total', 'card_difference', 'transfer_total', 'closing_status'] as $field) {
            $this->assertSame($web->$field, $mobile->$field);
        }
        $history = $this->getJson('/api/close-history-test')->assertOk();
        $ids = collect($history->json('data.items'))->pluck('id')->all();
        $this->assertContains($other->id, $ids);
        $this->assertContains($register->id, $ids);
        $entry = collect($history->json('data.items'))->firstWhere('id', $other->id);
        $this->assertSame('100.00', $entry['expected_cash']);
        $this->assertSame('1025.00', $entry['counted_cash']);
    }

    public function test_retry_survives_assignment_change_and_transfers_are_preserved(): void
    {
        [$user, $register, $payload] = $this->setupClose();
        $row = CashRegister::find($register->id);
        $this->sale(['user_id' => $user->id, 'branch_id' => $row->branch_id, 'inventory_location_id' => $row->inventory_location_id, 'GrandTotal' => 250]);
        $this->paymentSale(DB::table('sales')->max('id'), $this->paymentMethod('Transferencia')->id, 250);
        $payload['transfers_verified'] = true;
        $payload['transfer_notes'] = 'Banco revisado';
        $first = $this->actingAs($user, 'api')->postJson($this->url, $payload)->assertOk();
        $this->assertEquals(250, $first->json('summary.transfer_total'));
        $this->assertEquals(100, $first->json('summary.expected_cash'));
        $branch = $this->branch(); $location = $this->location($branch->id);
        $this->assignOperationalContext($user, $branch->id, $location->id);
        $retry = $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('idempotent', true);
        $this->assertSame($first->json('summary'), $retry->json('summary'));
    }

    public function test_ledger_failure_rolls_back_close(): void
    {
        [$user, $register, $payload] = $this->setupClose();
        DB::statement("CREATE TRIGGER fail_close_operation BEFORE INSERT ON cash_register_operations BEGIN SELECT RAISE(ABORT, 'simulated ledger failure'); END");
        try {
            app(\App\Services\Mobile\MobileCashRegisterCloseService::class)->close($user, $payload);
            $this->fail('Expected ledger failure');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('simulated ledger failure', $error->getMessage());
        }
        $this->assertSame('open', CashRegister::find($register->id)->status);
        $this->assertNull(CashRegister::find($register->id)->closing_snapshot);
        $this->assertSame(0, DB::table('cash_register_operations')->count());
    }
}
