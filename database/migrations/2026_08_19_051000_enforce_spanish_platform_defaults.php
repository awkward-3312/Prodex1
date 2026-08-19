<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('central');

        if (Schema::connection('central')->hasTable('central_languages')) {
            $db->table('central_languages')->update(['is_default' => false]);
            $db->table('central_languages')->where('locale', 'es')->update([
                'name' => 'Español',
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
            ]);

            $names = [
                'en' => 'Inglés', 'fr' => 'Francés', 'ar' => 'Árabe', 'hi' => 'Hindi',
                'bn' => 'Bengalí', 'tr' => 'Turco', 'de' => 'Alemán', 'pt' => 'Portugués',
                'ur' => 'Urdu',
            ];
            foreach ($names as $locale => $name) {
                $db->table('central_languages')->where('locale', $locale)->update(['name' => $name]);
            }
        }

        if (Schema::connection('central')->hasTable('general_settings')) {
            $row = $db->table('general_settings')->first();
            if ($row) {
                $updates = ['tenant_default_language' => 'es'];

                if (empty($row->app_name) || stripos((string) $row->app_name, 'stocky') !== false) {
                    $updates['app_name'] = 'PRODEX';
                }
                if (empty($row->company_name) || stripos((string) $row->company_name, 'stocky') !== false) {
                    $updates['company_name'] = 'PRODEX';
                }
                if (empty($row->tenant_app_name) || stripos((string) $row->tenant_app_name, 'stocky') !== false) {
                    $updates['tenant_app_name'] = 'PRODEX';
                }
                if (empty($row->tenant_company_name) || stripos((string) $row->tenant_company_name, 'stocky') !== false) {
                    $updates['tenant_company_name'] = 'PRODEX';
                }
                if (empty($row->tenant_footer_text) || stripos((string) $row->tenant_footer_text, 'stocky') !== false) {
                    $updates['tenant_footer_text'] = 'PRODEX';
                }
                if (empty($row->tenant_developed_by) || stripos((string) $row->tenant_developed_by, 'stocky') !== false) {
                    $updates['tenant_developed_by'] = 'PRODEX';
                }
                if (empty($row->tenant_page_title_suffix)
                    || stripos((string) $row->tenant_page_title_suffix, 'ultimate inventory') !== false
                    || stripos((string) $row->tenant_page_title_suffix, 'stocky') !== false) {
                    $updates['tenant_page_title_suffix'] = 'Gestión empresarial';
                }
                if (empty($row->dashboard_footer_text) || stripos((string) $row->dashboard_footer_text, 'stocky') !== false) {
                    $updates['dashboard_footer_text'] = '© '.date('Y').' PRODEX — Todos los derechos reservados.';
                }

                if (property_exists($row, 'subscription_reminder_sms')) {
                    $current = trim((string) ($row->subscription_reminder_sms ?? ''));
                    if ($current === '' || stripos($current, 'subscription') !== false || stripos($current, 'expires') !== false) {
                        $updates['subscription_reminder_sms'] = '{company}, tu suscripción al plan {plan} en {app} vence el {date} ({days} días). Renueva para evitar interrupciones.';
                    }
                }
                if (property_exists($row, 'trial_reminder_sms')) {
                    $current = trim((string) ($row->trial_reminder_sms ?? ''));
                    if ($current === '' || stripos($current, 'free trial') !== false || stripos($current, 'trial ends') !== false) {
                        $updates['trial_reminder_sms'] = '{company}, tu período de prueba de {app} termina el {date} ({days} días). Suscríbete para mantener activo tu espacio de trabajo.';
                    }
                }

                $db->table('general_settings')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Do not restore legacy English/Stocky defaults.
    }
};
