<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the active storefront theme selector to store_settings. The theme is
 * managed entirely from Store Settings and loaded automatically by the
 * storefront. Existing installs default to the original "default" theme so
 * the eCommerce storefront is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'theme')) {
                $table->string('theme', 32)->default('default')->after('store_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (Schema::hasColumn('store_settings', 'theme')) {
                $table->dropColumn('theme');
            }
        });
    }
};
