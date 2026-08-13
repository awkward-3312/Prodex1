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
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->string('provider', 64)->default('meta_cloud');
            // Meta Cloud API credentials (tokens stored encrypted at the model layer).
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('app_secret')->nullable();
            $table->string('webhook_verify_token')->nullable();
            $table->string('default_country_code', 8)->nullable();
            $table->boolean('is_connected')->default(false);
            $table->timestamp('connected_at')->nullable();
            // Per-trigger automation toggles, e.g. {"invoice_created": true, ...}
            $table->json('automations')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
    }
};
