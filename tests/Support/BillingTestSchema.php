<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the central billing migrations needed by Paddle
 * cancellation-semantics tests. Tests\TestCase only provisions
 * tenants/plans/general_settings — these tables are added on demand so each
 * suite stays scoped to what it actually needs.
 *
 * Mirrors:
 *   - 2026_03_06_100003_create_tenant_subscriptions_table
 *   - 2026_03_08_100004_add_subscription_billing_fields
 *   - 2026_03_15_000001_add_cancelled_at_to_tenant_subscriptions
 *   - 2026_09_11_000000_add_cancellation_requested_at_to_tenant_subscriptions
 *   - 2026_09_10_083500_create_paddle_billing_tables (paddle_subscriptions only)
 */
trait BillingTestSchema
{
    protected function buildBillingSchema(): void
    {
        if (! Schema::connection('central')->hasTable('tenant_subscriptions')) {
            Schema::connection('central')->create('tenant_subscriptions', function ($table) {
                $table->id();
                $table->string('tenant_id');
                $table->unsignedBigInteger('plan_id');
                $table->string('billing_cycle')->default('monthly');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->string('status')->default('active');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('cancellation_requested_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('central')->hasTable('tenant_billing_payments')) {
            Schema::connection('central')->create('tenant_billing_payments', function ($table) {
                $table->id();
                $table->string('tenant_id');
                $table->unsignedBigInteger('tenant_subscription_id')->nullable();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->decimal('amount', 12, 2);
                $table->decimal('tax', 12, 2)->nullable();
                $table->string('currency', 3)->default('USD');
                $table->string('gateway_currency', 3)->nullable();
                $table->decimal('gateway_amount', 12, 2)->nullable();
                $table->decimal('exchange_rate', 12, 8)->nullable();
                $table->boolean('conversion_applied')->default(false);
                $table->string('status')->default('pending');
                $table->string('gateway')->nullable();
                $table->string('gateway_payment_id')->nullable();
                $table->string('transaction_id')->nullable();
                $table->string('payment_proof_path')->nullable();
                $table->string('billing_cycle')->nullable();
                $table->string('invoice_number')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('central')->hasTable('paddle_subscriptions')) {
            Schema::connection('central')->create('paddle_subscriptions', function ($table) {
                $table->id();
                $table->string('tenant_id', 64)->index();
                $table->unsignedBigInteger('tenant_subscription_id')->unique();
                $table->string('paddle_subscription_id', 64)->unique();
                $table->string('paddle_customer_id', 64)->nullable();
                $table->string('paddle_price_id', 64)->nullable();
                $table->string('status', 32);
                $table->timestamp('next_billed_at')->nullable();
                $table->timestamp('current_period_starts_at')->nullable();
                $table->timestamp('current_period_ends_at')->nullable();
                $table->json('scheduled_change')->nullable();
                $table->json('custom_data')->nullable();
                $table->timestamp('last_event_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
