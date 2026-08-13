<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_logs')) {
            return;
        }

        Schema::create('shopify_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_store_id')->nullable()->index('shopify_logs_store_idx');
            $table->string('action', 100);
            $table->string('level', 20)->default('info');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_logs');
    }
};
