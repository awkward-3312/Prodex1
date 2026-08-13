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
            $table->string('currency_code', 10)->default('USD')->after('app_name');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
