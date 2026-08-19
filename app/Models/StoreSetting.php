<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    protected $fillable = [
        'enabled', 'registration_enabled', 'require_invite_code', 'require_admin_approval',
        'store_name', 'theme', 'logo_path', 'favicon_path',
        'primary_color', 'secondary_color', 'font_family',
        'hero_title', 'hero_subtitle', 'hero_image_path',
        'homepage_lineup', 'homepage_layout', 'social_links',
        'default_warehouse_id', 'allow_overselling', 'hide_out_of_stock', 'hide_prices_for_guests', 'show_stock', 'currency_code', 'language',
        'contact_email', 'contact_phone', 'contact_address',
        'seo_meta_title', 'seo_meta_description',
        'topbar_text_left', 'topbar_text_right', 'footer_text',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'registration_enabled' => 'boolean',
        'require_invite_code' => 'boolean',
        'require_admin_approval' => 'boolean',
        'allow_overselling' => 'boolean',
        'hide_out_of_stock' => 'boolean',
        'hide_prices_for_guests' => 'boolean',
        'show_stock' => 'boolean',
        'homepage_lineup' => 'array',
        'social_links' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (StoreSetting $setting): void {
            // The legacy Settings API still constructs this exact StoreX demo
            // payload when a tenant opens Online Store settings for the first
            // time. Normalize only that known legacy payload so custom store
            // creations and explicit language choices remain untouched.
            $isLegacyStoreX = ($setting->store_name === 'StoreX')
                && ($setting->hero_title === 'Sell online & in-store')
                && ($setting->hero_subtitle === 'Beautiful storefront. Synced inventory.');

            if (! $isLegacyStoreX) {
                return;
            }

            $setting->store_name = 'PRODEX Tienda';
            $setting->language = 'es';
            $setting->contact_email = 'info@prodexhub.cloud';
            $setting->contact_phone = '';
            $setting->contact_address = '';
            $setting->hero_title = 'Vende en línea y en tu negocio';
            $setting->hero_subtitle = 'Una tienda moderna conectada con tu inventario y punto de venta.';
            $setting->seo_meta_title = 'Tienda en línea';
            $setting->seo_meta_description = 'Tienda en línea conectada con PRODEX para mantener productos, ventas e inventario sincronizados.';
            $setting->topbar_text_left = 'Envíos y promociones según las condiciones de tu negocio';
            $setting->topbar_text_right = 'Compra fácil y segura';
            $setting->footer_text = 'Tienda en línea integrada con PRODEX.';
        });
    }
}
