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

                // Keep these references indexed rather than FK-constrained. Existing
                // tenant databases may carry historical signedness differences even
                // when the logical IDs are compatible. Application-level logistics
                // transactions enforce the relation and dispatched rows are immutable.
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_receipt_item_batches');
    }
};
