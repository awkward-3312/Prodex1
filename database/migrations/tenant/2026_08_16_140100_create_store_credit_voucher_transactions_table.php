<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_credit_voucher_transactions')) {
            return;
        }

        Schema::create('store_credit_voucher_transactions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('voucher_id')->index();
            $table->integer('sale_id')->nullable()->index();
            $table->integer('sale_return_id')->nullable()->index();
            $table->integer('user_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->string('type', 32)->index();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_voucher_transactions');
    }
};
