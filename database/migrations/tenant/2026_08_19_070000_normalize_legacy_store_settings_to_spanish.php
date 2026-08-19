<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_settings')) {
            return;
        }

        DB::table('store_settings')
            ->where('store_name', 'StoreX')
            ->where('hero_title', 'Sell online & in-store')
            ->where('hero_subtitle', 'Beautiful storefront. Synced inventory.')
            ->update([
                'store_name' => 'PRODEX Tienda',
                'language' => 'es',
                'contact_email' => 'info@prodexhub.cloud',
                'contact_phone' => '',
                'contact_address' => '',
                'hero_title' => 'Vende en línea y en tu negocio',
                'hero_subtitle' => 'Una tienda moderna conectada con tu inventario y punto de venta.',
                'seo_meta_title' => 'Tienda en línea',
                'seo_meta_description' => 'Tienda en línea conectada con PRODEX para mantener productos, ventas e inventario sincronizados.',
                'topbar_text_left' => 'Envíos y promociones según las condiciones de tu negocio',
                'topbar_text_right' => 'Compra fácil y segura',
                'footer_text' => 'Tienda en línea integrada con PRODEX.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring obsolete StoreX demo content
        // would reintroduce English branding into existing tenant storefronts.
    }
};
