<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasColumn('general_settings', 'landing_template')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->string('landing_template', 32)->default('landing-two');
            });
        }

        DB::connection('central')->table('general_settings')
            ->whereNull('landing_template')
            ->update(['landing_template' => 'landing-two']);
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasColumn('general_settings', 'landing_template')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->dropColumn('landing_template');
            });
        }
    }
};
