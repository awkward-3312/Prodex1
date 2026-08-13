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
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('demo_data_enabled');
            $table->string('whatsapp_provider', 64)->default('meta_cloud')->after('whatsapp_enabled');
            $table->json('whatsapp_default_templates')->nullable()->after('whatsapp_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_enabled', 'whatsapp_provider', 'whatsapp_default_templates']);
        });
    }
};
