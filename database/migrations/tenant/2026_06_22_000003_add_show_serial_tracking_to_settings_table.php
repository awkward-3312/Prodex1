<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global master switch for the Serial / IMEI tracking module. Defaults to false
 * so existing installs are completely unaffected until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'show_serial_tracking')) {
                $table->boolean('show_serial_tracking')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'show_serial_tracking')) {
                $table->dropColumn('show_serial_tracking');
            }
        });
    }
};
