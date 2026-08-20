<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('fiscal_tax_category', 20)->nullable()->after('tax_method');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->string('fiscal_tax_category', 20)->nullable()->after('tax_method');
            $table->decimal('fiscal_tax_rate', 5, 2)->nullable()->after('fiscal_tax_category');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('identification_type', 30)->nullable()->after('tax_number');
            $table->string('identification_number', 50)->nullable()->after('identification_type');
            $table->string('sar_registry_number', 100)->nullable()->after('identification_number');
            $table->string('exoneration_registry_number', 100)->nullable()->after('sar_registry_number');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->json('fiscal_exemption_data')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('fiscal_exemption_data');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'identification_type',
                'identification_number',
                'sar_registry_number',
                'exoneration_registry_number',
            ]);
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn(['fiscal_tax_category', 'fiscal_tax_rate']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('fiscal_tax_category');
        });
    }
};
