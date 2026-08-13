<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_serials — the serial / IMEI ledger.
 *
 * One row per physical unit. status drives the lifecycle; the row is never
 * deleted on a return (status changes instead) so history is preserved.
 * serial_number is globally unique to satisfy the "no duplicates across the
 * system" requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_serials')) {
            return;
        }

        Schema::create('product_serials', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->string('serial_number', 191);

            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->unsignedInteger('warehouse_id');

            // available | sold | returned_customer | returned_supplier | damaged | reserved
            $table->string('status', 20)->default('available');

            // Origin (purchase) linkage
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('purchase_detail_id')->nullable();
            $table->unsignedInteger('provider_id')->nullable();
            $table->double('cost')->nullable();

            // Current / last sale linkage
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('sale_detail_id')->nullable();
            $table->unsignedInteger('client_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps(6);

            $table->unique('serial_number', 'ps_serial_number_uq');
            $table->index(['product_id', 'warehouse_id', 'status'], 'ps_product_warehouse_status_idx');
            $table->index(['product_id', 'product_variant_id', 'warehouse_id', 'status'], 'ps_pvws_idx');
            $table->index('status', 'ps_status_idx');
            $table->index('purchase_id', 'ps_purchase_idx');
            $table->index('sale_id', 'ps_sale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
    }
};
