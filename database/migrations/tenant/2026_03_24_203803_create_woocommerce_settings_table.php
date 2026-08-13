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
        Schema::create('woocommerce_settings', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('store_url');
            $table->string('consumer_key');
            $table->string('consumer_secret');
            $table->string('wp_username')->nullable();
            $table->string('wp_app_password')->nullable();
            $table->boolean('enable_auto_sync')->default(false);
            $table->string('sync_interval')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woocommerce_settings');
    }
};
