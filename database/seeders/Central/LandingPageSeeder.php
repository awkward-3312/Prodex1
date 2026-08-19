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

        // Configuración general.
        if (! $central->table('general_settings')->exists()) {
            $central->table('general_settings')->insert([
                'app_name' => config('app.name', 'PRODEX'),
                'logo_path' => 'images/super/settings/logo-default.png',
                'favicon_path' => 'images/super/settings/favicon.ico',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Sección principal.
        if (! $central->table('landing_hero')->exists()) {
            $central->table('landing_hero')->insert([
                'title' => 'Gestiona tu negocio desde un solo lugar',
                'subtitle' => 'Ventas, inventario, compras, caja y más en PRODEX',
                'description' => 'Centraliza las operaciones de tu empresa en una plataforma en la nube diseñada para facilitar el control diario de tu negocio.',
                'primary_button_text' => 'Comenzar prueba gratis',
                'primary_button_url' => '/register',
                'secondary_button_text' => 'Conocer más',
                'secondary_button_url' => '#features',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Encabezado de características.
        if (! $central->table('landing_features_section')->exists()) {
            $central->table('landing_features_section')->insert([
                'section_title' => 'Todo lo que necesitas para gestionar tu negocio',
                'section_subtitle' => 'Herramientas conectadas para administrar operaciones, inventario, ventas y seguimiento desde una sola plataforma.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $central->table('landing_features')->exists()) {
            $features = [
                ['title' => 'Múltiples almacenes', 'description' => 'Controla existencias por ubicación y registra transferencias entre almacenes.', 'icon' => 'bi bi-building', 'sort_order' => 1],
                ['title' => 'Punto de venta', 'description' => 'Registra ventas presenciales desde un POS con productos, clientes, pagos y comprobantes.', 'icon' => 'bi bi-shop-window', 'sort_order' => 2],
                ['title' => 'Compras y ventas', 'description' => 'Administra compras, ventas, devoluciones y cotizaciones manteniendo el historial de cada operación.', 'icon' => 'bi bi-arrow-left-right', 'sort_order' => 3],
                ['title' => 'Reportes', 'description' => 'Consulta información de ventas, inventario, compras, gastos y otras áreas disponibles en tu cuenta.', 'icon' => 'bi bi-graph-up-arrow', 'sort_order' => 4],
                ['title' => 'Usuarios y permisos', 'description' => 'Crea usuarios y controla qué funciones puede utilizar cada persona mediante roles y permisos.', 'icon' => 'bi bi-people-fill', 'sort_order' => 5],
                ['title' => 'Integración con WooCommerce', 'description' => 'Conecta productos y operaciones compatibles con WooCommerce cuando esta función esté configurada.', 'icon' => 'bi bi-cart4', 'sort_order' => 6],
                ['title' => 'Tienda en línea', 'description' => 'Publica un catálogo en línea y recibe pedidos mediante las funciones de comercio electrónico disponibles.', 'icon' => 'bi bi-globe', 'sort_order' => 7],
                ['title' => 'Portal del cliente', 'description' => 'Permite que tus clientes consulten facturas, pagos, cotizaciones y otra información habilitada para ellos.', 'icon' => 'bi bi-person-badge', 'sort_order' => 8],
                ['title' => 'Soporte multilingüe', 'description' => 'PRODEX puede ofrecer otros idiomas, manteniendo Español como idioma predeterminado.', 'icon' => 'bi bi-translate', 'sort_order' => 9],
            ];

            foreach ($features as $feature) {
                $central->table('landing_features')->insert(array_merge($feature, [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        if (! $central->table('landing_pricing')->exists()) {
            $central->table('landing_pricing')->insert([
                'section_title' => 'Planes claros para tu negocio',
                'section_subtitle' => 'Elige el plan que mejor se adapte a tu operación y revisa sus funciones antes de contratar.',
                'show_monthly_pricing' => true,
                'show_yearly_pricing' => true,
                'load_plans_from_database' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $central->table('landing_testimonials')->exists()) {
            $testimonials = [
                ['client_name' => 'Sarah Johnson', 'company_name' => 'Urban Retail Co.', 'review' => 'La plataforma nos permite organizar inventario y ventas de distintas ubicaciones desde un solo lugar.', 'rating' => 5, 'sort_order' => 1],
                ['client_name' => 'Ahmed Benali', 'company_name' => 'MedSupply Direct', 'review' => 'El control por almacenes facilita el seguimiento de transferencias y existencias entre ubicaciones.', 'rating' => 5, 'sort_order' => 2],
                ['client_name' => 'Maria Chen', 'company_name' => 'FreshMart Grocery', 'review' => 'El punto de venta es fácil de utilizar y nos ayuda a mantener las operaciones diarias organizadas.', 'rating' => 4, 'sort_order' => 3],
            ];

            foreach ($testimonials as $testimonial) {
                $central->table('landing_testimonials')->insert(array_merge($testimonial, [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        if (! $central->table('landing_faqs')->exists()) {
            $faqs = [
                ['question' => '¿Cuánto dura la prueba gratuita?', 'answer' => 'La duración de la prueba se muestra durante el registro y depende de la configuración vigente de PRODEX. No necesitas seleccionar Español manualmente para comenzar.', 'sort_order' => 1],
                ['question' => '¿Puedo manejar más de un almacén?', 'answer' => 'Sí. PRODEX permite trabajar con varios almacenes cuando esta función está disponible en tu plan y configuración.', 'sort_order' => 2],
                ['question' => '¿Mis datos están separados de los de otras empresas?', 'answer' => 'Sí. PRODEX utiliza una arquitectura multitenant donde cada espacio de trabajo mantiene sus datos empresariales separados.', 'sort_order' => 3],
                ['question' => '¿Puedo importar productos existentes?', 'answer' => 'PRODEX incluye funciones de importación en distintos módulos. Antes de importar, revisa la plantilla y los campos requeridos para evitar duplicados o datos incompletos.', 'sort_order' => 4],
                ['question' => '¿Cómo puedo pagar un plan?', 'answer' => 'Los métodos disponibles se muestran al momento de contratar. Si la transferencia bancaria está habilitada, PRODEX mostrará las cuentas e instrucciones configuradas por el administrador.', 'sort_order' => 5],
            ];

            foreach ($faqs as $faq) {
                $central->table('landing_faqs')->insert(array_merge($faq, [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        if (! $central->table('landing_stats')->exists()) {
            $stats = [
                ['value' => 'ERP', 'label' => 'Gestión empresarial', 'icon' => 'bi bi-building', 'sort_order' => 1],
                ['value' => 'POS', 'label' => 'Punto de venta', 'icon' => 'bi bi-shop-window', 'sort_order' => 2],
                ['value' => 'Nube', 'label' => 'Acceso en línea', 'icon' => 'bi bi-cloud', 'sort_order' => 3],
                ['value' => 'Multi', 'label' => 'Almacenes y usuarios', 'icon' => 'bi bi-diagram-3', 'sort_order' => 4],
            ];

            foreach ($stats as $stat) {
                $central->table('landing_stats')->insert(array_merge($stat, [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        if (! $central->table('landing_cta')->exists()) {
            $central->table('landing_cta')->insert([
                'title' => 'Una sola herramienta para manejar tu negocio',
                'subtitle' => 'Crea tu espacio de trabajo y conoce todo lo que PRODEX puede hacer por tu empresa.',
                'button_text' => 'Comenzar prueba gratis',
                'button_url' => '/register',
                'sales_button_text' => 'Hablar con Ventas',
                'sales_button_url' => null,
                'is_active' => true,
                'show_commercial_cta' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $central->table('landing_footer')->exists()) {
            $central->table('landing_footer')->insert([
                'footer_about' => 'PRODEX es una plataforma de gestión empresarial para administrar ventas, inventario, compras, caja, clientes y otras operaciones desde un solo lugar.',
                'copyright_text' => '© ' . date('Y') . ' PRODEX. Todos los derechos reservados.',
                'contact_email' => 'soporte@prodexhub.cloud',
                'sales_email' => 'ventas@prodexhub.cloud',
                'sales_whatsapp_message' => 'Hola, me interesa conocer más sobre PRODEX y sus planes.',
                'show_sales_floating_button' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $central->table('landing_privacy_policy')->exists()) {
            $central->table('landing_privacy_policy')->insert([
                'introduction' => __('landing.privacy_intro_text'),
                'data_collection' => __('landing.privacy_collect_text'),
                'data_usage' => __('landing.privacy_use_text'),
                'cookies_usage' => __('landing.privacy_cookies_text'),
                'third_party' => __('landing.privacy_third_party_text'),
                'data_protection' => __('landing.privacy_protection_text'),
                'user_rights' => __('landing.privacy_rights_text'),
                'contact_info' => __('landing.privacy_contact_text'),
                'last_updated' => $now->toDateString(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($central->getSchemaBuilder()->hasTable('landing_terms_conditions')
            && ! $central->table('landing_terms_conditions')->exists()) {
            $central->table('landing_terms_conditions')->insert([
                'acceptance' => __('landing.terms_acceptance_text'),
                'use_license' => __('landing.terms_license_text'),
                'user_accounts' => __('landing.terms_accounts_text'),
                'payments' => __('landing.terms_payments_text'),
                'prohibited' => __('landing.terms_prohibited_text'),
                'intellectual_property' => __('landing.terms_ip_text'),
                'liability' => __('landing.terms_liability_text'),
                'governing_law' => __('landing.terms_law_text'),
                'last_updated' => $now->toDateString(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $central->table('landing_seo')->exists()) {
            $central->table('landing_seo')->insert([
                'meta_title' => 'PRODEX — Gestión empresarial, inventario y punto de venta',
                'meta_description' => 'Gestiona ventas, inventario, compras, almacenes, clientes y punto de venta desde una plataforma empresarial en la nube.',
                'meta_keywords' => 'ERP, gestión empresarial, inventario, punto de venta, POS, almacenes, ventas, compras, Honduras',
                'favicon' => 'images/super/settings/favicon.ico',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
