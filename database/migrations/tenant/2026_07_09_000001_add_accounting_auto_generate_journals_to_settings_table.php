<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'accounting_auto_generate_journals')) {
                // When true, journal entries are generated automatically from
                // operational events (sales, purchases, payments, expenses).
                // When false, journals must be created manually.
                $table->boolean('accounting_auto_generate_journals')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'accounting_auto_generate_journals')) {
                $table->dropColumn('accounting_auto_generate_journals');
            }
        });
    }
};
