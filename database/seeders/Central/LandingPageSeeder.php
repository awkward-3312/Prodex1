<?php

namespace Database\Seeders\Central;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LandingPageSeeder extends Seeder
{
    public function run(): void
    {
        $central = DB::connection('central');
        $now = now();

        // General settings (logo, favicon)
        if (! $central->table('general_settings')->exists()) {
            $central->table('general_settings')->insert([
                'app_name'     => config('app.name', 'Stocky'),
                'logo_path'    => 'images/super/settings/logo-default.png',
                'favicon_path' => 'images/super/settings/favicon.ico',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // Hero
        if (! $central->table('landing_hero')->exists()) {
            $central->table('landing_hero')->insert([
                'title'                => 'Manage Your Inventory Smarter',
                'subtitle'             => 'All-in-One Stock Management Platform',
                'description'          => 'Streamline your warehouse operations, track stock in real time, and grow your business with powerful POS, invoicing, and multi-location support.',
                'primary_button_text'  => 'Start Free Trial',
                'primary_button_url'   => '/register',
                'secondary_button_text' => 'Learn More',
                'secondary_button_url' => '#features',
                'is_active'            => true,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        // Features section header
        if (! $central->table('landing_features_section')->exists()) {
            $central->table('landing_features_section')->insert([
                'section_title'    => 'Everything You Need',
                'section_subtitle' => 'Powerful features to run your entire inventory operation from one place.',
                'is_active'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        // Feature items
        if (! $central->table('landing_features')->exists()) {
            $features = [
                ['title' => 'Multi-Warehouse',      'description' => 'Manage stock across unlimited warehouses and transfer between locations with a few clicks.',        'icon' => 'bi bi-building',        'sort_order' => 1],
                ['title' => 'Point of Sale',         'description' => 'Built-in POS system with barcode scanning, receipts, and multiple payment methods.',                'icon' => 'bi bi-shop-window',     'sort_order' => 2],
                ['title' => 'Purchase & Sales',      'description' => 'Handle purchases, sales, returns, and quotations with full document lifecycle tracking.',            'icon' => 'bi bi-arrow-left-right','sort_order' => 3],
                ['title' => 'Real-Time Reports',     'description' => 'Dashboards and detailed reports for stock levels, profit, top products, and more.',                  'icon' => 'bi bi-graph-up-arrow',  'sort_order' => 4],
                ['title' => 'Team & Permissions',    'description' => 'Invite your team and control access with granular role-based permissions.',                           'icon' => 'bi bi-people-fill',     'sort_order' => 5],
                ['title' => 'WooCommerce',           'description' => 'Sync your products, orders, and stock levels with WooCommerce for seamless online selling.',          'icon' => 'bi bi-cart4',           'sort_order' => 6],
                ['title' => 'Online Store',           'description' => 'Launch your own branded online store and let customers browse and order directly.',                   'icon' => 'bi bi-globe',           'sort_order' => 7],
                ['title' => 'Client Portal',          'description' => 'Give your clients a self-service portal to view invoices, track orders, and manage their account.',   'icon' => 'bi bi-person-badge',    'sort_order' => 8],
                ['title' => '24+ Languages',          'description' => 'Reach a global audience with built-in support for over 24 languages out of the box.',                'icon' => 'bi bi-translate',       'sort_order' => 9],
            ];

            foreach ($features as $f) {
                $central->table('landing_features')->insert(array_merge($f, [
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // Pricing section
        if (! $central->table('landing_pricing')->exists()) {
            $central->table('landing_pricing')->insert([
                'section_title'            => 'Simple, Transparent Pricing',
                'section_subtitle'         => 'Choose the plan that fits your business. Upgrade or downgrade anytime.',
                'show_monthly_pricing'     => true,
                'show_yearly_pricing'      => true,
                'load_plans_from_database' => true,
                'is_active'                => true,
                'created_at'               => $now,
                'updated_at'               => $now,
            ]);
        }

        // Testimonials
        if (! $central->table('landing_testimonials')->exists()) {
            $testimonials = [
                ['client_name' => 'Sarah Johnson',   'company_name' => 'Urban Retail Co.',   'review' => 'Stocky transformed how we manage inventory across our three stores. Real-time stock updates saved us from constant stock-outs.',                     'rating' => 5, 'sort_order' => 1],
                ['client_name' => 'Ahmed Benali',     'company_name' => 'MedSupply Direct',   'review' => 'The multi-warehouse feature is a game-changer. We reduced transfer errors by 80% in the first month.',                                              'rating' => 5, 'sort_order' => 2],
                ['client_name' => 'Maria Chen',       'company_name' => 'FreshMart Grocery',  'review' => 'Easy to set up and the POS works flawlessly. Our cashiers were comfortable with it from day one.',                                                   'rating' => 4, 'sort_order' => 3],
            ];

            foreach ($testimonials as $t) {
                $central->table('landing_testimonials')->insert(array_merge($t, [
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // FAQs
        if (! $central->table('landing_faqs')->exists()) {
            $faqs = [
                ['question' => 'How long is the free trial?',                    'answer' => 'Every new workspace comes with a 14-day free trial on the Professional plan. No credit card required.',                                                    'sort_order' => 1],
                ['question' => 'Can I manage multiple warehouses?',              'answer' => 'Yes. All paid plans support unlimited warehouses with stock transfers, adjustments, and per-location reporting.',                                                'sort_order' => 2],
                ['question' => 'Is my data secure?',                             'answer' => 'Absolutely. Each workspace has its own isolated database, encrypted connections, and automatic daily backups.',                                                   'sort_order' => 3],
                ['question' => 'Can I import my existing products?',             'answer' => 'Yes. You can bulk-import products, clients, and suppliers via CSV. We also offer WooCommerce sync for online stores.',                                            'sort_order' => 4],
                ['question' => 'What payment methods do you accept?',            'answer' => 'We accept all major credit cards via Stripe, as well as PayPal, Paystack, and Flutterwave depending on your region.',                                  'sort_order' => 5],
            ];

            foreach ($faqs as $f) {
                $central->table('landing_faqs')->insert(array_merge($f, [
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // Stats (Trust Bar)
        if (! $central->table('landing_stats')->exists()) {
            $stats = [
                ['value' => '10K+',   'label' => 'Businesses',    'icon' => 'bi bi-building',        'sort_order' => 1],
                ['value' => '50M+',   'label' => 'Items Managed', 'icon' => 'bi bi-box-seam',        'sort_order' => 2],
                ['value' => '99.9%',  'label' => 'Uptime',        'icon' => 'bi bi-shield-check',    'sort_order' => 3],
                ['value' => '4.8/5',  'label' => 'User Rating',   'icon' => 'bi bi-star-fill',       'sort_order' => 4],
            ];

            foreach ($stats as $s) {
                $central->table('landing_stats')->insert(array_merge($s, [
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // Call to action
        if (! $central->table('landing_cta')->exists()) {
            $central->table('landing_cta')->insert([
                'title'              => 'Una sola herramienta para manejar todo tu negocio',
                'subtitle'           => 'Comienza hoy con 7 días gratis. Sin tarjeta de crédito requerida.',
                'button_text'        => 'Prueba Gratis 7 Días',
                'button_url'         => '/register',
                'sales_button_text'  => 'Hablar con Ventas',
                'sales_button_url'   => null,
                'is_active'          => true,
                'show_commercial_cta'=> true,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        // Footer
        if (! $central->table('landing_footer')->exists()) {
            $central->table('landing_footer')->insert([
                'footer_about'               => 'Prodex es una plataforma moderna para administrar ventas, inventario, caja, clientes y operaciones desde un solo lugar.',
                'copyright_text'             => '© ' . date('Y') . ' Prodex. Todos los derechos reservados.',
                'contact_email'              => 'support@prodex.local',
                'sales_email'                => 'ventas@prodex.local',
                'sales_whatsapp_message'     => 'Hola, me interesa conocer más sobre Prodex y sus planes.',
                'show_sales_floating_button' => true,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }

        // Privacy Policy defaults
        if (! $central->table('landing_privacy_policy')->exists()) {
            $central->table('landing_privacy_policy')->insert([
                'introduction'    => __('landing.privacy_intro_text'),
                'data_collection' => __('landing.privacy_collect_text'),
                'data_usage'      => __('landing.privacy_use_text'),
                'cookies_usage'   => __('landing.privacy_cookies_text'),
                'third_party'     => __('landing.privacy_third_party_text'),
                'data_protection' => __('landing.privacy_protection_text'),
                'user_rights'     => __('landing.privacy_rights_text'),
                'contact_info'    => __('landing.privacy_contact_text'),
                'last_updated'    => $now->toDateString(),
                'is_active'       => true,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // Terms & Conditions defaults
        if ($central->getSchemaBuilder()->hasTable('landing_terms_conditions')
            && ! $central->table('landing_terms_conditions')->exists()) {
            $central->table('landing_terms_conditions')->insert([
                'acceptance'            => __('landing.terms_acceptance_text'),
                'use_license'           => __('landing.terms_license_text'),
                'user_accounts'         => __('landing.terms_accounts_text'),
                'payments'              => __('landing.terms_payments_text'),
                'prohibited'            => __('landing.terms_prohibited_text'),
                'intellectual_property' => __('landing.terms_ip_text'),
                'liability'             => __('landing.terms_liability_text'),
                'governing_law'         => __('landing.terms_law_text'),
                'last_updated'          => $now->toDateString(),
                'is_active'             => true,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }

        // SEO defaults
        if (! $central->table('landing_seo')->exists()) {
            $central->table('landing_seo')->insert([
                'meta_title'       => 'Stocky — Inventory & Stock Management Platform',
                'meta_description' => 'Manage inventory, warehouses, POS, purchases, and sales from one powerful platform. Start your free trial today.',
                'meta_keywords'    => 'inventory management, stock management, POS, warehouse, multi-location, saas',
                'favicon'          => 'images/super/settings/favicon.ico',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }
}
