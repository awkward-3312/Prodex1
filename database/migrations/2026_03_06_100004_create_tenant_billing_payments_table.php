<?php

declare(strict_types=1);

/**
 * Central migration: billing payments for tenant subscriptions.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantBillingPaymentsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenant_billing_payments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('tenant_subscription_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending'); // pending, paid, failed, refunded
            $table->string('gateway')->nullable(); // stripe, etc.
            $table->string('gateway_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('tenant_subscription_id')->references('id')->on('tenant_subscriptions')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_billing_payments');
    }
}
