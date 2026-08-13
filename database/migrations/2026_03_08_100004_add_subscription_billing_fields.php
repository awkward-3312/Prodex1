<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionBillingFields extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenant_subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle')->default('monthly')->after('plan_id');
            $table->decimal('amount', 12, 2)->default(0)->after('billing_cycle');
            $table->string('currency', 3)->default('USD')->after('amount');
            $table->timestamp('starts_at')->nullable()->after('status');
        });

        Schema::connection('central')->table('plans', function (Blueprint $table) {
            $table->decimal('yearly_price', 12, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'amount', 'currency', 'starts_at']);
        });

        Schema::connection('central')->table('plans', function (Blueprint $table) {
            $table->dropColumn('yearly_price');
        });
    }
}
