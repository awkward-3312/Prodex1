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
            if (! Schema::connection('central')->hasColumn('general_settings', 'demo_data_enabled')) {
                $table->boolean('demo_data_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn('demo_data_enabled');
        });
    }
};
