<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('promotions', 'usage_limit_total')) {
                $table->unsignedInteger('usage_limit_total')->nullable()->after('stackable');
            }
            if (! Schema::hasColumn('promotions', 'usage_limit_per_customer')) {
                $table->unsignedInteger('usage_limit_per_customer')->nullable()->after('usage_limit_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            foreach (['usage_limit_total', 'usage_limit_per_customer'] as $col) {
                if (Schema::hasColumn('promotions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
