<?php

namespace Database\Seeders\Central;

use App\Models\Central\CentralLanguage;
use Illuminate\Database\Seeder;

class CentralLanguagesSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['name' => 'Español',    'locale' => 'es', 'flag' => 'es.svg', 'is_default' => true,  'is_rtl' => false, 'sort_order' => 1],
            ['name' => 'Inglés',     'locale' => 'en', 'flag' => 'gb.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 2],
            ['name' => 'Francés',    'locale' => 'fr', 'flag' => 'fr.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 3],
            ['name' => 'Árabe',      'locale' => 'ar', 'flag' => 'sa.svg', 'is_default' => false, 'is_rtl' => true,  'sort_order' => 4],
            ['name' => 'Hindi',      'locale' => 'hi', 'flag' => 'in.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 5],
            ['name' => 'Bengalí',    'locale' => 'bn', 'flag' => 'bd.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 6],
            ['name' => 'Turco',      'locale' => 'tr', 'flag' => 'tr.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 7],
            ['name' => 'Alemán',     'locale' => 'de', 'flag' => 'de.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 8],
            ['name' => 'Portugués',  'locale' => 'pt', 'flag' => 'pt.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 9],
            ['name' => 'Urdu',       'locale' => 'ur', 'flag' => 'pk.svg', 'is_default' => false, 'is_rtl' => true,  'sort_order' => 10],
        ];

        foreach ($languages as $lang) {
            CentralLanguage::updateOrCreate(
                ['locale' => $lang['locale']],
                array_merge($lang, ['is_active' => true])
            );
        }
    }
}
