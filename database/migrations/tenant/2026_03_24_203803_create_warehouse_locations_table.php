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
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('warehouse_id')->index('warehouse_locations_warehouse_id');
            $table->string('code', 64);
            $table->string('name', 192)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->softDeletes();

            $table->unique(['warehouse_id', 'code'], 'warehouse_locations_wh_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
    }
};
