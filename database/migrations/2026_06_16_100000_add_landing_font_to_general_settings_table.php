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
            if (! Schema::connection('central')->hasColumn('general_settings', 'landing_font')) {
                $table->string('landing_font', 100)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'landing_heading_font')) {
                $table->string('landing_heading_font', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            foreach (['landing_font', 'landing_heading_font'] as $column) {
                if (Schema::connection('central')->hasColumn('general_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
