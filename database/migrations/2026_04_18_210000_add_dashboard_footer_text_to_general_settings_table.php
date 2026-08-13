<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_FOOTER_TEXT = '© 2026 Stocky Inc. — All rights reserved.';

    public function up(): void
    {
        if (! Schema::connection('central')->hasColumn('general_settings', 'dashboard_footer_text')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->string('dashboard_footer_text', 500)->nullable()->default(self::DEFAULT_FOOTER_TEXT);
            });
        }

        DB::connection('central')
            ->table('general_settings')
            ->whereNull('dashboard_footer_text')
            ->update(['dashboard_footer_text' => self::DEFAULT_FOOTER_TEXT]);
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasColumn('general_settings', 'dashboard_footer_text')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->dropColumn('dashboard_footer_text');
            });
        }
    }
};
