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
        Schema::create('online_orders', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->date('date')->nullable()->index();
            $table->time('time')->nullable();
            $table->string('ref', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->integer('client_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->decimal('total', 15)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('online_orders');
    }
};
