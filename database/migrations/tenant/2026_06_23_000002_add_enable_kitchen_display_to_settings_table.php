<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global master switch for the Kitchen Display module. Defaults to false so existing
 * tenants are completely unaffected until an admin opts in. The setting gates the POS
 * routing options and kitchen-ticket creation (not menu visibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_kitchen_display')) {
                $table->boolean('enable_kitchen_display')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'enable_kitchen_display')) {
                $table->dropColumn('enable_kitchen_display');
            }
        });
    }
};
