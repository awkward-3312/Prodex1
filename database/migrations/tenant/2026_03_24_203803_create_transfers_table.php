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
        Schema::create('transfers', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('user_id_transfers');
            $table->string('Ref', 192);
            $table->date('date');
            $table->time('time')->nullable();
            $table->integer('from_warehouse_id')->index('from_warehouse_id');
            $table->integer('to_warehouse_id')->index('to_warehouse_id');
            $table->decimal('items', 15);
            $table->decimal('tax_rate', 15)->nullable()->default(0);
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->decimal('shipping', 15)->nullable()->default(0);
            $table->decimal('GrandTotal', 15)->default(0);
            $table->string('statut', 192);
            $table->string('approval_status', 50)->default('pending');
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
        Schema::dropIfExists('transfers');
    }
};
