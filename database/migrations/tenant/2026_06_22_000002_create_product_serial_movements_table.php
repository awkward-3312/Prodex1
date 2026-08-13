<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_serial_movements — append-only audit log of every lifecycle event
 * for a serial / IMEI. Powers the serial history view and the movement report.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_serial_movements')) {
            return;
        }

        Schema::create('product_serial_movements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->unsignedBigInteger('product_serial_id');
            $table->string('serial_number', 191);

            // purchased | sold | sale_returned | purchase_returned | status_changed | adjusted
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->unsignedInteger('warehouse_id')->nullable();

            // Polymorphic-ish reference to the source document.
            $table->string('reference_type', 40)->nullable(); // Sale | Purchase | SaleReturn | PurchaseReturn
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->unsignedInteger('user_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('created_at', 6)->nullable();

            $table->index('product_serial_id', 'psm_serial_idx');
            $table->index('serial_number', 'psm_serial_number_idx');
            $table->index(['reference_type', 'reference_id'], 'psm_reference_idx');
            $table->index('action', 'psm_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serial_movements');
    }
};
