<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_stores')) {
            return;
        }

        Schema::create('shopify_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            // e.g. my-store.myshopify.com
            $table->string('shop_domain', 191);
            // Admin API access token of a custom app (shpat_...)
            $table->text('access_token')->nullable();
            // API secret key of the custom app — used to verify webhook HMAC signatures
            $table->text('api_secret')->nullable();
            $table->string('api_version', 20)->default('2025-01');
            // Shopify location used for inventory sync
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_name', 191)->nullable();
            // Stocky warehouse mapped to that location (null = aggregate all warehouses)
            $table->integer('warehouse_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('enable_auto_sync')->default(false);
            $table->string('sync_interval', 32)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps(6);

            $table->unique('shop_domain', 'shopify_stores_shop_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_stores');
    }
};
