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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('user_id_returns');
            $table->date('date');
            $table->time('time')->nullable();
            $table->string('Ref', 192);
            $table->integer('purchase_id')->nullable()->index('purchase_id_purchase_returns');
            $table->integer('provider_id')->index('provider_id_return');
            $table->integer('warehouse_id')->index('purchase_return_warehouse_id');
            $table->decimal('tax_rate', 15)->nullable()->default(0);
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->decimal('shipping', 15)->nullable()->default(0);
            $table->decimal('GrandTotal', 15);
            $table->decimal('paid_amount', 15)->default(0);
            $table->string('payment_statut', 192);
            $table->string('statut', 192);
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
