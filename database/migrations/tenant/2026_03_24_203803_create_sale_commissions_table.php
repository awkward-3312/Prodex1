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
        Schema::create('sale_commissions', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->increments('id');
            $table->integer('sale_id')->index();
            $table->unsignedInteger('sales_agent_id')->index();
            $table->unsignedInteger('commission_program_id')->index();
            $table->unsignedInteger('commission_rule_id')->index();
            $table->decimal('base_amount', 14, 4)->default(0);
            $table->decimal('commission_amount', 14, 4)->default(0);
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('commission_receipt_id')->nullable()->index();
            $table->timestamp('calculated_at')->nullable();
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
        Schema::dropIfExists('sale_commissions');
    }
};
