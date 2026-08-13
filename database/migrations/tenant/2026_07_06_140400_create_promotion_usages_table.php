<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per time a promotion was applied to a sale. Used to enforce total/per-customer
     * usage caps and to drive promotion-performance reports.
     *
     * No DB-level foreign keys (matches the codebase convention); referential integrity is
     * enforced at the application layer.
     */
    public function up(): void
    {
        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id')->index();
            $table->integer('sale_id')->nullable()->index();
            $table->integer('client_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->string('code', 64)->nullable();
            $table->dateTime('used_at')->index();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::drop('promotion_usages');
    }
};
