<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddTimezoneToSettingsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('settings', 'timezone')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->string('timezone', 64)->nullable();
            });
        }

        // Until now every tenant shared APP_TIMEZONE from the central .env,
        // so copy that value into the tenant row to preserve behavior after
        // the switch to per-tenant storage.
        DB::table('settings')->whereNull('timezone')->update([
            'timezone' => env('APP_TIMEZONE') ?: 'UTC',
        ]);
    }

    public function down()
    {
        if (Schema::hasColumn('settings', 'timezone')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('timezone');
            });
        }
    }
}
