<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transfer_receipt_item_batch_issues')) return;

        Schema::create('transfer_receipt_item_batch_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_receipt_item_id')->index();
            $table->unsignedBigInteger('transfer_detail_batch_id')->index();
            $table->unsignedBigInteger('source_batch_id')->index();
            $table->unsignedBigInteger('destination_batch_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
            $table->string('issue_type', 20)->index();
            $table->decimal('quantity', 20, 6);
            $table->decimal('resolved_quantity', 20, 6)->default(0);
            $table->string('resolution_status', 30)->default('open')->index();
            $table->string('resolution_code', 60)->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['transfer_receipt_item_id', 'transfer_detail_batch_id', 'issue_type'],
                'transfer_batch_issue_receipt_pivot_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_receipt_item_batch_issues');
    }
};
