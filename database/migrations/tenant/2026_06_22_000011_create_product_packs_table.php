<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_packs — per-product selling packs for the Multi-Pack Selling module.
 *
 * Each row is one sellable pack (e.g. "Single", "6-Pack", "Case of 24") with its
 * own quantity multiplier and selling price. Inventory is always kept in the
 * product's single base unit; `multiplier` converts a pack quantity to base
 * units at sale/return time. Every product that uses packs has exactly one
 * default pack with multiplier = 1. The table is opt-in: products without any
 * pack rows behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_packs')) {
            return;
        }

        Schema::create('product_packs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->unsignedInteger('product_id');
            $table->string('name', 191);
            $table->double('multiplier')->default(1);
            $table->double('price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id', 'pp_product_idx');
            $table->index(['product_id', 'is_active'], 'pp_product_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packs');
    }
};
