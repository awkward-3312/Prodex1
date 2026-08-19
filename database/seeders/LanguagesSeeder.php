<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguagesSeeder extends Seeder
{
    public function run()
    {
        DB::table('languages')->insert([
            ['name' => 'Inglés', 'locale' => 'en', 'flag' => 'gb.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Francés', 'locale' => 'fr', 'flag' => 'fr.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Árabe', 'locale' => 'ar', 'flag' => 'sa.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Turco', 'locale' => 'tur', 'flag' => 'tr.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Tailandés', 'locale' => 'thai', 'flag' => 'th.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Hindi', 'locale' => 'hn', 'flag' => 'in.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Alemán', 'locale' => 'de', 'flag' => 'de.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Español', 'locale' => 'es', 'flag' => 'es.svg', 'is_active' => true, 'is_default' => true],
            ['name' => 'Italiano', 'locale' => 'it', 'flag' => 'it.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Indonesio', 'locale' => 'Ind', 'flag' => 'id.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Chino simplificado', 'locale' => 'sm_ch', 'flag' => 'cn.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Chino tradicional', 'locale' => 'tr_ch', 'flag' => 'cn.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Ruso', 'locale' => 'ru', 'flag' => 'ru.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Vietnamita', 'locale' => 'vn', 'flag' => 'vn.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Coreano', 'locale' => 'kr', 'flag' => 'kr.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Bengalí', 'locale' => 'ba', 'flag' => 'bd.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Portugués', 'locale' => 'br', 'flag' => 'pt.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Danés', 'locale' => 'da', 'flag' => 'dk.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Japonés', 'locale' => 'ja', 'flag' => 'jp.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Polaco', 'locale' => 'pl', 'flag' => 'pl.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Suajili', 'locale' => 'sw', 'flag' => 'ke.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Hausa', 'locale' => 'ha', 'flag' => 'ng.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Yoruba', 'locale' => 'yo', 'flag' => 'ng.svg', 'is_active' => true, 'is_default' => false],
            ['name' => 'Amárico', 'locale' => 'am', 'flag' => 'et.svg', 'is_active' => true, 'is_default' => false],
        ]);

        // Set default language from settings; Spanish is the platform fallback.
        $defaultLocale = DB::table('settings')->value('default_language') ?? 'es';
        DB::table('languages')->update(['is_default' => false]);
        DB::table('languages')->where('locale', $defaultLocale)->update(['is_default' => true]);
    }
}
