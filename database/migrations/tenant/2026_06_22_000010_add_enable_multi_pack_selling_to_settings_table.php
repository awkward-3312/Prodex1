<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global master switch for the Multi-Pack Selling module. Defaults to false so
 * existing installs are completely unaffected until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_multi_pack_selling')) {
                $table->boolean('enable_multi_pack_selling')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'enable_multi_pack_selling')) {
                $table->dropColumn('enable_multi_pack_selling');
            }
        });
    }
};
