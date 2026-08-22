<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transfer_detail_serials')) return;

        Schema::create('transfer_detail_serials', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('transfer_detail_id')->index();
            $table->integer('product_serial_id')->index();
            $table->integer('transfer_receipt_item_id')->nullable()->index();
            $table->string('status', 30)->default('in_transit')->index();
            $table->string('issue_type', 30)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['transfer_detail_id', 'product_serial_id'], 'transfer_detail_serial_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_detail_serials');
    }
};
