<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_mappings')) {
            return;
        }

        Schema::create('shopify_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_store_id');
            // product | variant | customer | order | category | brand
            $table->string('entity_type', 32);
            // Local Stocky id (products.id, product_variants.id, clients.id, sales.id, ...)
            $table->unsignedBigInteger('local_id');
            // Remote Shopify id
            $table->unsignedBigInteger('shopify_id');
            // Extra remote identifiers (e.g. inventory_item_id, parent product id, order number)
            $table->json('extra')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps(6);

            $table->unique(['shopify_store_id', 'entity_type', 'local_id'], 'shopify_mappings_store_type_local_unique');
            $table->index(['shopify_store_id', 'entity_type', 'shopify_id'], 'shopify_mappings_store_type_remote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_mappings');
    }
};
