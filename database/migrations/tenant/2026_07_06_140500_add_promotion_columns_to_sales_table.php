<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'promotion_discount')) {
                $table->decimal('promotion_discount', 14, 2)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('sales', 'promotion_code')) {
                $table->string('promotion_code', 64)->nullable()->after('promotion_discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            foreach (['promotion_discount', 'promotion_code'] as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
