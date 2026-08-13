<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasColumn('general_settings', 'show_customizer_button')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->boolean('show_customizer_button')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasColumn('general_settings', 'show_customizer_button')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->dropColumn('show_customizer_button');
            });
        }
    }
};
