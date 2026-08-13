<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many pivot between promotions and warehouses.
     *
     * Cleanup is handled at the application layer (PromotionsController syncs the
     * pivot; soft-deleting a Promotion via Eloquent doesn't touch this table, which
     * is fine because the index excludes deleted_at-aware queries). No DB-level
     * foreign keys, matching the existing `warehouses` table convention.
     */
    public function up(): void
    {
        Schema::create('promotion_warehouse', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id')->index();
            $table->integer('warehouse_id')->index();
            $table->timestamps(6);

            $table->unique(['promotion_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('promotion_warehouse');
    }
};
