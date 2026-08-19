<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('settings')) {
            $setting = DB::table('settings')->first();
            if ($setting) {
                $updates = [];
                if (Schema::hasColumn('settings', 'default_language')) $updates['default_language'] = 'es';
                if (Schema::hasColumn('settings', 'locale')) $updates['locale'] = 'es-HN';
                if (Schema::hasColumn('settings', 'footer')) $updates['footer'] = 'PRODEX';
                if (Schema::hasColumn('settings', 'developed_by')) $updates['developed_by'] = 'PRODEX';

                if (Schema::hasColumn('settings', 'app_name')
                    && (empty($setting->app_name) || stripos((string) $setting->app_name, 'stocky') !== false)) {
                    $updates['app_name'] = 'PRODEX';
                }
                if (Schema::hasColumn('settings', 'CompanyName')
                    && (empty($setting->CompanyName) || stripos((string) $setting->CompanyName, 'stocky') !== false)) {
                    $updates['CompanyName'] = 'PRODEX';
                }
                if (Schema::hasColumn('settings', 'page_title_suffix')
                    && (empty($setting->page_title_suffix)
                        || stripos((string) $setting->page_title_suffix, 'ultimate inventory') !== false
                        || stripos((string) $setting->page_title_suffix, 'stocky') !== false)) {
                    $updates['page_title_suffix'] = 'Gestión empresarial';
                }

                if ($updates) DB::table('settings')->where('id', $setting->id)->update($updates);
            }
        }

        if (Schema::hasTable('languages')) {
            DB::table('languages')->update(['is_default' => false]);
            DB::table('languages')->where('locale', 'es')->update(['name' => 'Español', 'is_active' => true, 'is_default' => true]);
            $names = [
                'en'=>'Inglés','fr'=>'Francés','ar'=>'Árabe','tur'=>'Turco','thai'=>'Tailandés','hn'=>'Hindi',
                'de'=>'Alemán','it'=>'Italiano','Ind'=>'Indonesio','sm_ch'=>'Chino simplificado','tr_ch'=>'Chino tradicional',
                'ru'=>'Ruso','vn'=>'Vietnamita','kr'=>'Coreano','ba'=>'Bengalí','br'=>'Portugués','da'=>'Danés',
                'ja'=>'Japonés','pl'=>'Polaco','sw'=>'Suajili','ha'=>'Hausa','yo'=>'Yoruba','am'=>'Amárico',
            ];
            foreach ($names as $locale => $name) {
                DB::table('languages')->where('locale', $locale)->update(['name' => $name]);
            }
        }

        if (Schema::hasTable('translations')) {
            if (Schema::hasColumn('translations', 'is_default')) {
                DB::table('translations')->update(['is_default' => false]);
                DB::table('translations')->where('locale', 'es')->update(['is_default' => true]);
            }

            $translations = require database_path('seeders/translations/es.php');
            $translations = array_replace($translations, config('prodex_spanish_ui.tenant_translations', []));

            foreach ($translations as $key => $value) {
                $existing = DB::table('translations')->where('locale', 'es')->where('key', $key)->first();
                $row = [
                    'value' => $value,
                    'updated_at' => $now,
                ];
                if (Schema::hasColumn('translations', 'is_default')) $row['is_default'] = true;

                if ($existing) {
                    DB::table('translations')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('translations')->insert(array_merge([
                        'locale' => 'es',
                        'key' => $key,
                        'created_at' => $now,
                    ], $row));
                }
            }
        }
    }

    public function down(): void
    {
        // Do not restore legacy English defaults or translations.
    }
};
