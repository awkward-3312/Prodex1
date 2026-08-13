<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('draft_sales', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('draft_sales_user_id');
            $table->date('date');
            $table->string('Ref', 192);
            $table->integer('client_id')->index('draft_sales_client_id');
            $table->integer('warehouse_id')->index('draft_sales_warehouse_id');
            $table->decimal('tax_rate', 15)->nullable()->default(0);
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_Method', 10)->default('2');
            $table->decimal('shipping', 15)->nullable()->default(0);
            $table->decimal('GrandTotal', 15)->default(0);
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draft_sales');
    }
};
