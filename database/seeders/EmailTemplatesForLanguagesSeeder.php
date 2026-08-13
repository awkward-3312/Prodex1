<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailTemplatesForLanguagesSeeder extends Seeder
{
    public function run()
    {
        if (!Schema::hasColumn('email_messages', 'locale')) {
            return;
        }

        $locales = [];
        if (Schema::hasTable('languages')) {
            $locales = DB::table('languages')->pluck('locale')->unique()->values()->all();
        }
        if (empty($locales)) {
            return;
        }

        $englishTemplates = DB::table('email_messages')
            ->where('locale', 'en')
            ->whereNull('deleted_at')
            ->get(['name', 'subject', 'body']);

        if ($englishTemplates->isEmpty()) {
            return;
        }

        $now = now();
        foreach ($locales as $locale) {
            if ($locale === 'en') {
                continue;
            }
            foreach ($englishTemplates as $row) {
                $exists = DB::table('email_messages')
                    ->where('name', $row->name)
                    ->where('locale', $locale)
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$exists) {
                    DB::table('email_messages')->insert([
                        'name' => $row->name,
                        'locale' => $locale,
                        'subject' => $row->subject,
                        'body' => $row->body,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}
