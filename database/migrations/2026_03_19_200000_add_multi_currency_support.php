<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gateway-level currency configuration
        Schema::connection('central')->table('payment_gateway_settings', function (Blueprint $table) {
            $table->json('supported_currencies')->nullable()->after('test_mode');
            $table->string('default_currency', 10)->nullable()->after('supported_currencies');
        });

        // Dual-currency fields on payment records
        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->string('gateway_currency', 10)->nullable()->after('currency');
            $table->decimal('gateway_amount', 12, 2)->nullable()->after('gateway_currency');
            $table->decimal('exchange_rate', 16, 8)->nullable()->after('gateway_amount');
            $table->boolean('conversion_applied')->default(false)->after('exchange_rate');
        });

        // Same fields on pending registrations (new signup checkout)
        Schema::connection('central')->table('pending_registrations', function (Blueprint $table) {
            $table->string('gateway_currency', 10)->nullable()->after('currency');
            $table->decimal('gateway_amount', 12, 2)->nullable()->after('gateway_currency');
            $table->decimal('exchange_rate', 16, 8)->nullable()->after('gateway_amount');
            $table->boolean('conversion_applied')->default(false)->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('payment_gateway_settings', function (Blueprint $table) {
            $table->dropColumn(['supported_currencies', 'default_currency']);
        });

        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->dropColumn(['gateway_currency', 'gateway_amount', 'exchange_rate', 'conversion_applied']);
        });

        Schema::connection('central')->table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn(['gateway_currency', 'gateway_amount', 'exchange_rate', 'conversion_applied']);
        });
    }
};
