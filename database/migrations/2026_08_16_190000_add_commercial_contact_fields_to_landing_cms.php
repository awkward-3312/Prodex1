<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('landing_cta', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('landing_cta', 'sales_button_text')) {
                $table->string('sales_button_text')->nullable()->after('button_url');
            }
            if (! Schema::connection('central')->hasColumn('landing_cta', 'sales_button_url')) {
                $table->string('sales_button_url')->nullable()->after('sales_button_text');
            }
            if (! Schema::connection('central')->hasColumn('landing_cta', 'show_commercial_cta')) {
                $table->boolean('show_commercial_cta')->default(true)->after('is_active');
            }
        });

        Schema::connection('central')->table('landing_footer', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('landing_footer', 'sales_email')) {
                $table->string('sales_email')->nullable()->after('contact_phone');
            }
            if (! Schema::connection('central')->hasColumn('landing_footer', 'sales_whatsapp_number')) {
                $table->string('sales_whatsapp_number', 50)->nullable()->after('sales_email');
            }
            if (! Schema::connection('central')->hasColumn('landing_footer', 'sales_whatsapp_message')) {
                $table->string('sales_whatsapp_message', 500)->nullable()->after('sales_whatsapp_number');
            }
            if (! Schema::connection('central')->hasColumn('landing_footer', 'show_sales_floating_button')) {
                $table->boolean('show_sales_floating_button')->default(true)->after('show_admin_login');
            }
        });

        DB::connection('central')->table('landing_cta')->update([
            'sales_button_text' => DB::raw("COALESCE(sales_button_text, 'Hablar con Ventas')"),
            'show_commercial_cta' => DB::raw('COALESCE(show_commercial_cta, 1)'),
        ]);

        DB::connection('central')->table('landing_footer')->update([
            'sales_email' => DB::raw('COALESCE(sales_email, contact_email)'),
            'sales_whatsapp_message' => DB::raw("COALESCE(sales_whatsapp_message, 'Hola, me interesa conocer más sobre Prodex y sus planes.')"),
            'show_sales_floating_button' => DB::raw('COALESCE(show_sales_floating_button, 1)'),
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->table('landing_footer', function (Blueprint $table) {
            foreach (['show_sales_floating_button', 'sales_whatsapp_message', 'sales_whatsapp_number', 'sales_email'] as $column) {
                if (Schema::connection('central')->hasColumn('landing_footer', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::connection('central')->table('landing_cta', function (Blueprint $table) {
            foreach (['show_commercial_cta', 'sales_button_url', 'sales_button_text'] as $column) {
                if (Schema::connection('central')->hasColumn('landing_cta', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
