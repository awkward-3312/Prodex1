<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_3_decimal_pricing')) {
                $table->boolean('enable_3_decimal_pricing')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'enable_3_decimal_pricing')) {
                $table->dropColumn('enable_3_decimal_pricing');
            }
        });
    }
};
