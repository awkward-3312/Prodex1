<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'purchase_extra_charges_enabled')) {
                $table->boolean('purchase_extra_charges_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'purchase_custom_fields_enabled')) {
                $table->boolean('purchase_custom_fields_enabled')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'purchase_extra_charges_enabled')) {
                $table->dropColumn('purchase_extra_charges_enabled');
            }
            if (Schema::hasColumn('settings', 'purchase_custom_fields_enabled')) {
                $table->dropColumn('purchase_custom_fields_enabled');
            }
        });
    }
};
