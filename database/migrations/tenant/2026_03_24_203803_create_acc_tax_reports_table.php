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
        Schema::create('acc_tax_reports', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('type', 16)->default('vat');
            $table->decimal('taxable_sales', 20, 6)->default(0);
            $table->decimal('output_tax', 20, 6)->default(0);
            $table->decimal('taxable_purchases', 20, 6)->default(0);
            $table->decimal('input_tax', 20, 6)->default(0);
            $table->decimal('net_tax', 20, 6)->default(0);
            $table->string('source', 16)->default('auto');
            $table->timestamp('generated_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acc_tax_reports');
    }
};
