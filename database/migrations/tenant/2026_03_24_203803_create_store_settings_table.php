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
        Schema::create('store_settings', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->boolean('enabled')->default(true);
            $table->string('store_name')->default('StoreX');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color')->default('#6c5ce7');
            $table->string('secondary_color')->default('#00c2ff');
            $table->string('font_family')->default('Poppins, system-ui, Segoe UI, Roboto, Arial, sans-serif');
            $table->string('hero_title')->nullable();
            $table->text('hero_subtitle')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->json('homepage_lineup')->nullable();
            $table->string('homepage_layout', 32)->default('default');
            $table->json('social_links')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->boolean('allow_overselling')->default(true);
            $table->boolean('hide_out_of_stock')->default(false);
            $table->boolean('hide_prices_for_guests')->default(false);
            $table->string('currency_code', 8)->default('$');
            $table->string('language', 10)->default('en');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_address')->nullable();
            $table->string('seo_meta_title')->nullable();
            $table->text('seo_meta_description')->nullable();
            $table->string('topbar_text_left')->nullable();
            $table->string('topbar_text_right')->nullable();
            $table->string('footer_text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
