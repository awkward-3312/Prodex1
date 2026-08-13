<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the show_stock toggle to store_settings. The StoreSetting model and
 * Store Settings API already read/write this field; the column was never
 * created. Defaults to true to match the storefront fallback
 * ($s->show_stock ?? true) so existing installs keep showing stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'show_stock')) {
                $table->boolean('show_stock')->default(true)->after('hide_prices_for_guests');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (Schema::hasColumn('store_settings', 'show_stock')) {
                $table->dropColumn('show_stock');
            }
        });
    }
};
