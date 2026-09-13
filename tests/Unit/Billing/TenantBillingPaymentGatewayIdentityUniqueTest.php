<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * (gateway, gateway_payment_id) is already relied on at the app level
 * (findOrCreateTransactionPayment() keys firstOrCreate() on exactly this
 * pair) but had no DB-level guarantee — two concurrent requests that both
 * miss the firstOrCreate() lookup could both insert. This exercises the
 * actual migration file (not a hand-rolled copy of it) against the same
 * hand-built schema the rest of this suite uses, so both the constraint
 * itself and the pre-flight data-safety checks are proven for real.
 */
class TenantBillingPaymentGatewayIdentityUniqueTest extends TestCase
{
    use BillingTestSchema;

    private const MIGRATION_PATH = __DIR__ . '/../../../database/migrations/2026_09_13_000000_add_unique_gateway_payment_identity_to_tenant_billing_payments.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        DB::connection('central')->table('tenants')->insert(['id' => 'tenant-1']);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);
    }

    private function applyMigration(): void
    {
        $migration = require self::MIGRATION_PATH;
        $migration->up();
    }

    private function makePayment(array $overrides = []): TenantBillingPayment
    {
        return TenantBillingPayment::create(array_merge([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'amount' => 11.49,
            'currency' => 'USD',
            'status' => TenantBillingPayment::STATUS_PENDING,
        ], $overrides));
    }

    public function test_the_migration_file_exists_and_is_the_one_under_test(): void
    {
        $this->assertFileExists(self::MIGRATION_PATH);
    }

    public function test_two_payments_with_the_same_gateway_and_id_cannot_coexist_after_the_migration(): void
    {
        $this->applyMigration();

        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => 'sess_123']);

        $this->expectException(QueryException::class);
        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => 'sess_123']);
    }

    public function test_different_gateways_with_the_same_id_value_are_not_a_conflict(): void
    {
        $this->applyMigration();

        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => 'shared_id']);
        $this->makePayment(['gateway' => 'paypal', 'gateway_payment_id' => 'shared_id']);

        $this->assertDatabaseCount('tenant_billing_payments', 2, 'central');
    }

    public function test_multiple_null_gateway_payment_ids_do_not_collide(): void
    {
        $this->applyMigration();

        // Manual entries recorded via Super\PaymentController::store() never
        // set gateway_payment_id at all — must remain unlimited regardless
        // of how many share the same gateway.
        $this->makePayment(['gateway' => 'manual', 'gateway_payment_id' => null]);
        $this->makePayment(['gateway' => 'manual', 'gateway_payment_id' => null]);
        $this->makePayment(['gateway' => 'manual', 'gateway_payment_id' => null]);

        $this->assertDatabaseCount('tenant_billing_payments', 3, 'central');
    }

    public function test_the_migration_normalizes_legacy_empty_string_ids_to_null_instead_of_blocking(): void
    {
        // Simulate pre-existing data from before markPaid()'s falsy guard
        // (or any other legacy write path) that left '' instead of NULL.
        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => '']);
        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => '']);

        $this->applyMigration();

        $this->assertDatabaseCount('tenant_billing_payments', 2, 'central');
        $this->assertSame(
            0,
            TenantBillingPayment::where('gateway_payment_id', '')->count(),
            'Expected every empty-string gateway_payment_id to be normalized to NULL.'
        );

        // The constraint must still be live afterward.
        $this->makePayment(['gateway' => 'paypal', 'gateway_payment_id' => 'order_1']);
        $this->expectException(QueryException::class);
        $this->makePayment(['gateway' => 'paypal', 'gateway_payment_id' => 'order_1']);
    }

    public function test_the_migration_refuses_to_run_over_pre_existing_real_duplicates(): void
    {
        // Two independent payment rows already sharing a real
        // (gateway, gateway_payment_id) pair — the migration must not
        // silently drop/merge one of them; it must abort for a human to
        // resolve manually.
        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => 'dup_1']);
        $this->makePayment(['gateway' => 'stripe', 'gateway_payment_id' => 'dup_1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');

        $this->applyMigration();
    }
}
