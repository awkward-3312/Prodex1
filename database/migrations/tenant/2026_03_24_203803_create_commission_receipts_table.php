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
        Schema::create('commission_receipts', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->increments('id');
            $table->unsignedInteger('sales_agent_id')->index();
            $table->string('Ref', 192)->unique();
            $table->decimal('amount', 14, 4)->default(0);
            $table->date('paid_at');
            $table->integer('payment_method_id')->nullable()->index('commission_receipts_payment_method_id_foreign');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_receipts');
    }
};
