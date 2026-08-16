<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'store_credit_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('store_credit_amount', 15, 2)->default(0)->after('promotion_code');
            });
        }

        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('sale_returns', 'refund_mode')) $table->string('refund_mode', 32)->nullable()->after('payment_statut');
                if (! Schema::hasColumn('sale_returns', 'store_credit_voucher_id')) $table->integer('store_credit_voucher_id')->nullable()->index()->after('refund_mode');
                if (! Schema::hasColumn('sale_returns', 'store_credit_amount')) $table->decimal('store_credit_amount', 15, 2)->default(0)->after('store_credit_voucher_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_returns')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('sale_returns', 'refund_mode') ? 'refund_mode' : null,
                Schema::hasColumn('sale_returns', 'store_credit_voucher_id') ? 'store_credit_voucher_id' : null,
                Schema::hasColumn('sale_returns', 'store_credit_amount') ? 'store_credit_amount' : null,
            ]));

            if (! empty($columns)) {
                Schema::table('sale_returns', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'store_credit_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('store_credit_amount');
            });
        }
    }
};
