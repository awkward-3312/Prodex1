<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendTenantBillingPaymentsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->nullable()->after('tenant_subscription_id');
            $table->string('transaction_id')->nullable()->after('gateway_payment_id');
            $table->string('billing_cycle')->default('monthly')->after('status');
            $table->string('invoice_number')->nullable()->unique()->after('billing_cycle');
            $table->decimal('tax', 12, 2)->default(0)->after('amount');
            $table->text('notes')->nullable()->after('metadata');

            $table->foreign('plan_id')->references('id')->on('plans')->onUpdate('cascade')->onDelete('set null');
            $table->index(['status', 'paid_at']);
            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropIndex(['status', 'paid_at']);
            $table->dropIndex(['invoice_number']);
            $table->dropColumn(['plan_id', 'transaction_id', 'billing_cycle', 'invoice_number', 'tax', 'notes']);
        });
    }
}
