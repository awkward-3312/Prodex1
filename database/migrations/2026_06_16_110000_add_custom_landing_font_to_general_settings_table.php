<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('general_settings', 'landing_custom_font_name')) {
                $table->string('landing_custom_font_name', 50)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'landing_custom_font_path')) {
                $table->string('landing_custom_font_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            foreach (['landing_custom_font_name', 'landing_custom_font_path'] as $column) {
                if (Schema::connection('central')->hasColumn('general_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
