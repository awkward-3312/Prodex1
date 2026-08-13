<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a `translations` JSON column to every landing CMS table.
 *
 * Format: {"fr": {"title": "…", "description": "…"}, "ar": {…}}
 * The base columns keep the default-locale (English) content.
 */
return new class extends Migration
{
    protected array $tables = [
        'landing_hero',
        'landing_features_section',
        'landing_features',
        'landing_pricing',
        'landing_testimonials',
        'landing_faqs',
        'landing_cta',
        'landing_footer',
        'landing_seo',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::connection('central')->hasTable($table)
                && ! Schema::connection('central')->hasColumn($table, 'translations')) {
                Schema::connection('central')->table($table, function (Blueprint $t) {
                    $t->json('translations')->nullable()->after('id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::connection('central')->hasTable($table)
                && Schema::connection('central')->hasColumn($table, 'translations')) {
                Schema::connection('central')->table($table, function (Blueprint $t) {
                    $t->dropColumn('translations');
                });
            }
        }
    }
};
