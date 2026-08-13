<?php

namespace Database\Seeders\Central;

use App\Models\Central\CentralLanguage;
use Illuminate\Database\Seeder;

class CentralLanguagesSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['name' => 'English',    'locale' => 'en', 'flag' => 'gb.svg', 'is_default' => true,  'is_rtl' => false, 'sort_order' => 1],
            ['name' => 'French',     'locale' => 'fr', 'flag' => 'fr.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 2],
            ['name' => 'Arabic',     'locale' => 'ar', 'flag' => 'sa.svg', 'is_default' => false, 'is_rtl' => true,  'sort_order' => 3],
            ['name' => 'Spanish',    'locale' => 'es', 'flag' => 'es.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 4],
            ['name' => 'Hindi',      'locale' => 'hi', 'flag' => 'in.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 5],
            ['name' => 'Bengali',    'locale' => 'bn', 'flag' => 'bd.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 6],
            ['name' => 'Turkish',    'locale' => 'tr', 'flag' => 'tr.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 7],
            ['name' => 'German',     'locale' => 'de', 'flag' => 'de.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 8],
            ['name' => 'Portuguese', 'locale' => 'pt', 'flag' => 'pt.svg', 'is_default' => false, 'is_rtl' => false, 'sort_order' => 9],
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
