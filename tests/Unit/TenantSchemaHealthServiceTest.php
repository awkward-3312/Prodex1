<?php

namespace Tests\Unit;

use App\Services\TenantSchemaHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantSchemaHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.tenant' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        DB::purge('tenant');
        DB::connection('tenant')->getPdo();
    }

    public function test_it_reports_missing_store_credit_transactions_as_schema_requirement(): void
    {
        $this->createModernTenantSchema(false);

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertContains('Falta tabla: store_credit_voucher_transactions', $missing);
    }

    public function test_it_reports_no_missing_requirements_when_modern_schema_exists(): void
    {
        $this->createModernTenantSchema(true);

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertSame([], $missing);
    }

    private function createModernTenantSchema(bool $withStoreCreditTransactions): void
    {
        Schema::connection('tenant')->create('cash_registers', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('difference', 15, 2)->nullable();
            $table->json('counted_denominations')->nullable();
            $table->json('sales_by_payment_method')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('cash_difference', 15, 2)->nullable();
            $table->decimal('card_system_total', 15, 2)->nullable();
            $table->decimal('card_terminal_total', 15, 2)->nullable();
            $table->decimal('card_difference', 15, 2)->nullable();
            $table->string('card_batch_number')->nullable();
            $table->string('card_reference')->nullable();
            $table->text('card_notes')->nullable();
            $table->decimal('transfer_total', 15, 2)->nullable();
            $table->boolean('transfers_verified')->default(false);
            $table->text('transfer_notes')->nullable();
            $table->decimal('cash_withdrawn_at_close', 15, 2)->nullable();
            $table->decimal('next_opening_float', 15, 2)->nullable();
            $table->json('closing_snapshot')->nullable();
            $table->string('register_number_snapshot')->nullable();
            $table->unsignedInteger('opened_by_user_id_snapshot')->nullable();
            $table->string('opened_by_user_name_snapshot')->nullable();
            $table->unsignedInteger('closed_by_user_id')->nullable();
            $table->string('closed_by_user_name_snapshot')->nullable();
            $table->unsignedInteger('warehouse_id_snapshot')->nullable();
            $table->string('warehouse_name_snapshot')->nullable();
            $table->string('tenant_id_snapshot')->nullable();
            $table->date('opened_date_snapshot')->nullable();
            $table->time('opened_time_snapshot')->nullable();
            $table->date('closed_date_snapshot')->nullable();
            $table->time('closed_time_snapshot')->nullable();
            $table->unsignedInteger('session_duration_seconds')->nullable();
            $table->string('closing_status', 20)->nullable();
        });

        Schema::connection('tenant')->create('store_credit_vouchers', function (Blueprint $table) {
            $table->increments('id');
        });

        if ($withStoreCreditTransactions) {
            Schema::connection('tenant')->create('store_credit_voucher_transactions', function (Blueprint $table) {
                $table->increments('id');
            });
        }

        Schema::connection('tenant')->create('sales', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('store_credit_amount', 15, 2)->default(0);
        });

        Schema::connection('tenant')->create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->string('refund_mode', 32)->nullable();
            $table->integer('store_credit_voucher_id')->nullable();
            $table->decimal('store_credit_amount', 15, 2)->default(0);
        });
    }
}
