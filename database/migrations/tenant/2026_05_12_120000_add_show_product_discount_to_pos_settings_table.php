<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            if (! Schema::hasColumn('pos_settings', 'show_product_discount')) {
                $table->boolean('show_product_discount')->default(0)->after('show_discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'show_product_discount')) {
                $table->dropColumn('show_product_discount');
            }
        });
    }
};
