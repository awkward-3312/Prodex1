<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_credit_vouchers')) {
            return;
        }

        Schema::create('store_credit_vouchers', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('code', 64)->unique();
            $table->string('tenant_id', 128)->nullable()->index();
            $table->integer('client_id')->nullable()->index();
            $table->integer('original_sale_id')->nullable()->index();
            $table->integer('sale_return_id')->nullable()->unique();
            $table->integer('warehouse_id')->nullable()->index();
            $table->integer('issued_by_user_id')->nullable()->index();
            $table->decimal('original_amount', 15, 2)->default(0);
            $table->decimal('remaining_balance', 15, 2)->default(0);
            $table->string('currency', 16)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_vouchers');
    }
};
