<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $now = now();

        foreach ([
            'es' => 'Manual PRODEX',
            'en' => 'PRODEX Manual',
        ] as $locale => $value) {
            DB::table('translations')->updateOrInsert(
                ['locale' => $locale, 'key' => 'Knowledge_Base'],
                [
                    'value' => $value,
                    'is_default' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        DB::table('translations')
            ->where('locale', 'es')
            ->where('key', 'Knowledge_Base')
            ->update(['value' => 'Base de conocimientos', 'updated_at' => now()]);

        DB::table('translations')
            ->where('locale', 'en')
            ->where('key', 'Knowledge_Base')
            ->update(['value' => 'Knowledge Base', 'updated_at' => now()]);
    }
};
