<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DemoTenant\DemoTenantSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class DemoTenantPosResponseTest extends TestCase
{
    private function posResponseError($response): ?string
    {
        $seeder = new DemoTenantSeeder('retail');
        $method = new ReflectionMethod($seeder, 'posResponseError');
        $method->setAccessible(true);

        return $method->invoke($seeder, $response);
    }

    private function callSeederMethod(string $name, ...$arguments)
    {
        $seeder = new DemoTenantSeeder('retail');
        $method = new ReflectionMethod($seeder, $name);
        $method->setAccessible(true);

        return $method->invoke($seeder, ...$arguments);
    }

    public function test_successful_pos_json_response_is_accepted(): void
    {
        $this->assertNull($this->posResponseError(new JsonResponse(['success' => true, 'id' => 123], 200)));
    }

    public function test_pos_error_json_response_is_reported_with_status_and_message(): void
    {
        $error = $this->posResponseError(new JsonResponse([
            'success' => false,
            'message' => 'El método de pago no es válido.',
        ], 422));

        $this->assertSame('CreatePOS() respondió HTTP 422: El método de pago no es válido.', $error);
    }

    public function test_success_false_is_reported_even_when_response_status_is_not_an_error(): void
    {
        $error = $this->posResponseError(new JsonResponse([
            'success' => false,
            'code' => 'POS_REJECTED',
        ], 200));

        $this->assertSame('CreatePOS() respondió HTTP 200: POS_REJECTED', $error);
    }

    public function test_it_skips_a_role_one_user_without_pos_permission_and_selects_the_next_authorized_user(): void
    {
        $roleOne = new User(['id' => 10, 'role_id' => 1]);
        $authorized = new User(['id' => 11, 'role_id' => 2]);
        $roleOne->setAttribute('id', 10);
        $authorized->setAttribute('id', 11);
        $selected = $this->callSeederMethod(
            'selectDemoPosActingUserFromCandidates',
            [$roleOne, $authorized],
            fn (User $candidate): bool => (int) $candidate->id === 11
        );

        $this->assertEquals($authorized, $selected);
    }

    public function test_it_prefers_cash_even_when_cash_does_not_use_id_two(): void
    {
        $methodId = $this->callSeederMethod('selectActivePaymentMethodId', [
            (object) ['id' => 2, 'name' => 'Credit Card'],
            (object) ['id' => 9, 'name' => 'CaSh'],
            (object) ['id' => 11, 'name' => 'Bank Transfer'],
        ]);

        $this->assertSame(9, $methodId);
    }

    private function createProductionPaymentMethodsSchema(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_it_resolves_cash_without_is_active_in_the_production_schema(): void
    {
        $this->createProductionPaymentMethodsSchema();
        DB::table('payment_methods')->insert([
            ['id' => 3, 'name' => 'Credit Card'],
            ['id' => 9, 'name' => 'Cash'],
        ]);

        $this->assertFalse(Schema::hasColumn('payment_methods', 'is_active'));
        $this->assertSame(9, $this->callSeederMethod('activePaymentMethodId'));
    }

    public function test_it_uses_the_lowest_available_method_when_production_schema_has_no_cash(): void
    {
        $this->createProductionPaymentMethodsSchema();
        DB::table('payment_methods')->insert([
            ['id' => 8, 'name' => 'Bank Transfer'],
            ['id' => 4, 'name' => 'Credit Card'],
        ]);

        $this->assertSame(4, $this->callSeederMethod('activePaymentMethodId'));
    }

    public function test_generic_demo_acting_user_remains_the_first_active_owner_for_non_pos_modules(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('role_id');
            $table->softDeletes();
        });
        DB::table('users')->insert([
            ['id' => 7, 'role_id' => 2],
            ['id' => 9, 'role_id' => 1],
        ]);

        $user = $this->callSeederMethod('demoActingUser');

        $this->assertSame(9, (int) $user->id);
    }

    public function test_pos_acting_user_is_reserved_for_sales_and_not_transfers(): void
    {
        $source = file_get_contents(app_path('Services/DemoTenant/DemoTenantSeeder.php'));
        $transferStart = strpos($source, 'public function seedTransfers(): array');
        $transferEnd = strpos($source, 'public function seedClients(): array');
        $salesStart = strpos($source, 'public function seedSales(): array');
        $salesEnd = strpos($source, 'private function activePaymentMethodId(): ?int');
        $transferBody = substr($source, $transferStart, $transferEnd - $transferStart);
        $salesBody = substr($source, $salesStart, $salesEnd - $salesStart);

        $this->assertStringContainsString('$this->demoActingUser()', $transferBody);
        $this->assertStringNotContainsString('$this->demoPosActingUser()', $transferBody);
        $this->assertStringContainsString('$this->demoPosActingUser()', $salesBody);
        $this->assertSame(1, substr_count($source, '$this->demoPosActingUser()'));
    }

    public function test_it_reports_a_failed_sale_when_pos_succeeds_without_persisting_the_sale(): void
    {
        $marker = 'demo2-sale-0100000000000000000000000000';

        $error = $this->callSeederMethod('posSalePersistenceError', $marker);

        $this->assertStringContainsString('no persistió una venta', $error);
        $this->assertStringContainsString($marker, $error);
    }
}
