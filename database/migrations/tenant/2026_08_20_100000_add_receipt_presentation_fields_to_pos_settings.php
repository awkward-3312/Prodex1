<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->string('receipt_header_alignment', 10)->default('center')->after('receipt_paper_size');
            $table->string('receipt_fiscal_alignment', 10)->default('center')->after('receipt_header_alignment');
            $table->string('receipt_customer_alignment', 10)->default('left')->after('receipt_fiscal_alignment');
            $table->string('receipt_items_alignment', 10)->default('left')->after('receipt_customer_alignment');
            $table->string('receipt_totals_alignment', 10)->default('right')->after('receipt_items_alignment');
            $table->string('receipt_footer_alignment', 10)->default('center')->after('receipt_totals_alignment');
            $table->string('receipt_qr_alignment', 10)->default('center')->after('receipt_footer_alignment');
            $table->unsignedTinyInteger('receipt_font_size')->default(10)->after('receipt_qr_alignment');
            $table->string('receipt_density', 12)->default('normal')->after('receipt_font_size');
            $table->string('receipt_separator', 12)->default('dotted')->after('receipt_density');
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->dropColumn([
                'receipt_header_alignment',
                'receipt_fiscal_alignment',
                'receipt_customer_alignment',
                'receipt_items_alignment',
                'receipt_totals_alignment',
                'receipt_footer_alignment',
                'receipt_qr_alignment',
                'receipt_font_size',
                'receipt_density',
                'receipt_separator',
            ]);
        });
    }
};
