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
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('warehouse_id')->index();
            $table->decimal('opening_balance', 15);
            $table->decimal('closing_balance', 15)->nullable();
            $table->decimal('total_sales', 15)->default(0);
            $table->decimal('cash_in', 15)->default(0);
            $table->decimal('cash_out', 15)->default(0);
            $table->decimal('difference', 15)->nullable();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
