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
        Schema::create('shipments', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('shipment_user_id');
            $table->timestamp('date')->useCurrent();
            $table->string('Ref', 192);
            $table->integer('sale_id')->index('shipment_sale_id');
            $table->string('delivered_to', 192)->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('status', 192);
            $table->text('shipping_details')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
