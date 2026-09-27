<?php
namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileClientWriteController;
use App\Http\Controllers\Mobile\MobileClientsController;
use App\Models\Client;
use App\Models\Permission;
use App\Services\ClientMaintenanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MobileClientsReportsTestSchema;
use Tests\TestCase;

class MobileCustomerManagementTest extends TestCase
{
    use MobileClientsReportsTestSchema;
    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        $this->app->instance(ClientMaintenanceService::class, new class extends ClientMaintenanceService {
            public function resolveTenantTaxConfig(): array { return ['country_code' => 'HN', 'customer_tax_id_label' => 'RTN']; }
        });
        Route::middleware('auth:api')->post('/api/mobile/customer-test', [MobileClientWriteController::class, 'store']);
        Route::middleware('auth:api')->put('/api/mobile/customer-test/{id}', [MobileClientWriteController::class, 'update']);
        Route::middleware('auth:api')->get('/api/mobile/customer-test/{id}/edit', [MobileClientWriteController::class, 'edit']);
        Route::middleware('auth:api')->get('/api/mobile/customer-list-test', MobileClientsController::class);
    }
    private function schema(): void
    {
        $this->createMobileClientsReportsSchema();
        Schema::table('clients', function ($table) {
            foreach (['firstname', 'lastname', 'country', 'state', 'city', 'zip'] as $field) $table->string($field)->nullable();
            $table->integer('is_royalty_eligible')->default(0);
        });
        Schema::create('ecommerce_clients', function ($table) { $table->id(); $table->unsignedBigInteger('client_id')->nullable(); $table->string('email')->nullable(); $table->string('username')->nullable(); $table->timestamps(); $table->softDeletes(); });
        (require database_path('migrations/tenant/2026_09_14_000000_create_mobile_customer_operations_table.php'))->up();
        foreach (['Customers_add', 'Customers_edit', 'Customers_view'] as $name) Permission::firstOrCreate(['name' => $name], ['label' => $name]);
    }
    private function payload(array $extra = []): array { return array_merge(['operation_uuid' => (string) Str::uuid(), 'name' => 'Comercial Norte', 'email' => 'north@example.com', 'tax_number' => '0801-1990-123456'], $extra); }
    public function test_create_replay_conflict_canonical_and_listing(): void
    {
        $user = $this->user([], ['Customers_add', 'Customers_view']);
        $payload = $this->payload();
        $first = $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $payload)->assertOk()->assertJsonPath('idempotent', false)->assertJsonPath('data.tax_number', '08011990123456');
        $this->assertIsString($first->json('data.code'));
        $this->assertArrayNotHasKey('opening_balance', $first->json('data'));
        $this->postJson('/api/mobile/customer-test', $payload)->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/mobile/customer-test', array_replace($payload, ['name' => 'Otra']))->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, DB::table('clients')->count());
        $this->assertSame(1, DB::table('mobile_customer_operations')->count());
        $this->getJson('/api/mobile/customer-list-test')->assertOk()->assertJsonPath('data.items.0.id', $first->json('data.id'));
    }
    public function test_web_creation_still_supports_financial_fields_and_shared_fiscal_normalization(): void
    {
        Route::middleware('auth:api')->post('/web-client-test', [\App\Http\Controllers\ClientController::class, 'store']);
        $user = $this->user([], ['Customers_add']);
        $this->actingAs($user, 'api')->postJson('/web-client-test', ['name' => '', 'tax_number' => '', 'opening_balance' => 100, 'credit_limit' => 200])->assertOk()->assertJsonPath('name', 'Cliente Final');
        $this->assertEquals(100, Client::first()->opening_balance);
        $this->assertEquals(200, Client::first()->credit_limit);
        $this->assertSame(0, DB::table('mobile_customer_operations')->count());
    }
    public function test_non_honduran_tax_identifier_is_not_reinterpreted_as_rtn(): void
    {
        $this->app->instance(ClientMaintenanceService::class, new class extends ClientMaintenanceService {
            public function resolveTenantTaxConfig(): array { return ['country_code' => 'GT', 'customer_tax_id_label' => 'NIT']; }
        });
        $user = $this->user([], ['Customers_add']);
        $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $this->payload(['tax_number' => '123456-K', 'country' => 'Guatemala']))->assertOk()->assertJsonPath('data.tax_number', '123456-K')->assertJsonPath('data.country', 'Guatemala');
    }
    public function test_customer_ledger_is_registered_in_upgrade_and_schema_health(): void
    {
        $this->assertContains('database/migrations/tenant/2026_09_14_000000_create_mobile_customer_operations_table.php', \App\Services\TenantSchemaHealthService::CONTROLLED_MIGRATIONS);
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        $this->assertContains('Falta tabla: mobile_customer_operations', app(\App\Services\TenantSchemaHealthService::class)->missingRequirements());
    }
    public function test_authentication_and_exact_permissions(): void
    {
        $this->postJson('/api/mobile/customer-test', $this->payload())->assertUnauthorized();
        $user = $this->user([], ['Customers_view']);
        $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $this->payload())->assertForbidden();
        $this->putJson('/api/mobile/customer-test/1', ['name' => 'Test'])->assertForbidden();
    }
    public static function invalidFields(): array
    {
        return [['name', ''], ['name', ['bad']], ['email', 'bad'], ['tax_number', '00000000000000'], ['phone', str_repeat('1', 51)], ['tenant_id', 1], ['company_id', 1], ['organization_id', 1], ['id', 55], ['opening_balance', 50], ['credit_limit', 999], ['points', 50], ['branch_id', 1], ['operation_uuid', 'bad']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function test_invalid_creation_has_no_customer_or_ledger(string $field, mixed $value): void
    {
        $user = $this->user([], ['Customers_add']);
        $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $this->payload([$field => $value]))->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(0, DB::table('clients')->count());
        $this->assertSame(0, DB::table('mobile_customer_operations')->count());
    }
    public function test_edit_is_repeatable_preserves_financial_fields_and_hidden_ids(): void
    {
        $user = $this->user([], ['Customers_edit']);
        $client = $this->client(['name' => 'Antes', 'opening_balance' => 123, 'credit_limit' => 456, 'code' => 12]);
        $this->actingAs($user, 'api')->getJson('/api/mobile/customer-test/'.$client->id.'/edit')->assertOk()->assertJsonPath('data.name', 'Antes');
        $payload = ['name' => 'Después', 'phone' => '12345', 'email' => 'after@example.com'];
        for ($i = 0; $i < 2; $i++) $this->putJson('/api/mobile/customer-test/'.$client->id, $payload)->assertOk()->assertJsonPath('data.id', $client->id)->assertJsonPath('data.name', 'Después');
        $this->assertEquals(123, Client::find($client->id)->opening_balance);
        $this->assertEquals(456, Client::find($client->id)->credit_limit);
        $this->assertSame(0, DB::table('mobile_customer_operations')->count());
        foreach (['id', 'tenant_id', 'company_id', 'organization_id', 'opening_balance', 'credit_limit', 'points'] as $field) $this->putJson('/api/mobile/customer-test/'.$client->id, $payload + [$field => 999])->assertStatus(422);
        $this->putJson('/api/mobile/customer-test/'.$client->id, ['name' => ''])->assertStatus(422);
        $this->putJson('/api/mobile/customer-test/9999', $payload)->assertNotFound();
        DB::table('clients')->where('id', $client->id)->update(['deleted_at' => now()]);
        $this->putJson('/api/mobile/customer-test/'.$client->id, $payload)->assertNotFound()->assertJsonPath('error.code', 'customer_not_found');
    }
    public function test_other_operator_cannot_replay_uuid_and_email_uniqueness_remains(): void
    {
        $first = $this->user([], ['Customers_add']); $second = $this->user([], ['Customers_add']);
        $payload = $this->payload();
        $this->actingAs($first, 'api')->postJson('/api/mobile/customer-test', $payload)->assertOk();
        $this->actingAs($second, 'api')->postJson('/api/mobile/customer-test', $payload)->assertStatus(409);
        $this->postJson('/api/mobile/customer-test', array_replace($payload, ['operation_uuid' => (string) Str::uuid()]))->assertStatus(422);
    }
    public function test_ledger_failure_rolls_back_created_client(): void
    {
        $user = $this->user([], ['Customers_add']);
        DB::statement("CREATE TRIGGER fail_customer_operation BEFORE INSERT ON mobile_customer_operations BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $this->payload())->assertStatus(500);
        $this->assertSame(0, DB::table('clients')->count());
        $this->assertSame(0, DB::table('mobile_customer_operations')->count());
    }
    public function test_uuid_unique_constraint_is_the_last_concurrent_retry_guard(): void
    {
        $user = $this->user([], ['Customers_add']); $payload = $this->payload();
        $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $payload)->assertOk();
        $saved = (array) DB::table('mobile_customer_operations')->first(); unset($saved['id']);
        try { DB::table('mobile_customer_operations')->insert($saved); $this->fail('UUID must be unique'); } catch (\Illuminate\Database\QueryException $expected) { $this->assertSame(1, DB::table('mobile_customer_operations')->count()); }
    }
    public function test_tenant_connection_separates_ids_and_uuid_ledger(): void
    {
        $user = $this->user([], ['Customers_add', 'Customers_edit']); $payload = $this->payload();
        $this->actingAs($user, 'api')->postJson('/api/mobile/customer-test', $payload)->assertOk();
        $original = DB::getDefaultConnection();
        config(['database.connections.customer_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('customer_other');
        try {
            $this->schema();
            $otherUser = $this->user([], ['Customers_add', 'Customers_edit']);
            $this->actingAs($otherUser, 'api')->putJson('/api/mobile/customer-test/1', ['name' => 'Cross tenant'])->assertNotFound();
            $this->postJson('/api/mobile/customer-test', $payload)->assertOk()->assertJsonPath('idempotent', false);
            $this->assertSame(1, DB::table('clients')->count());
        } finally { DB::setDefaultConnection($original); DB::purge('customer_other'); }
        $this->assertSame('Comercial Norte', Client::first()->name);
    }
}
