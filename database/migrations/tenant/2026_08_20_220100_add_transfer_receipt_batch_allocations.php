<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_receipt_item_batches')) {
            Schema::create('transfer_receipt_item_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transfer_receipt_item_id')->index();
                $table->unsignedBigInteger('transfer_detail_batch_id')->index();
                $table->unsignedBigInteger('source_batch_id')->index();
                $table->unsignedBigInteger('destination_batch_id')->nullable()->index();
                $table->decimal('quantity_good', 20, 6)->default(0);
                $table->timestamps();

                $table->foreign('transfer_receipt_item_id')->references('id')->on('transfer_receipt_items')->onDelete('cascade');
                $table->foreign('transfer_detail_batch_id')->references('id')->on('transfer_detail_batches')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_receipt_item_batches');
    }
};
