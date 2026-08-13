<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_app_name')) {
                $table->string('tenant_app_name')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_company_name')) {
                $table->string('tenant_company_name')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_email')) {
                $table->string('tenant_email')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_phone')) {
                $table->string('tenant_phone', 50)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_address')) {
                $table->string('tenant_address', 500)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_logo_path')) {
                $table->string('tenant_logo_path')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_favicon_path')) {
                $table->string('tenant_favicon_path')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_currency_code')) {
                $table->string('tenant_currency_code', 10)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_currency_symbol')) {
                $table->string('tenant_currency_symbol', 10)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_default_language')) {
                $table->string('tenant_default_language', 10)->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_footer_text')) {
                $table->string('tenant_footer_text')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_page_title_suffix')) {
                $table->string('tenant_page_title_suffix')->nullable();
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'tenant_developed_by')) {
                $table->string('tenant_developed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn([
                'tenant_app_name',
                'tenant_company_name',
                'tenant_email',
                'tenant_phone',
                'tenant_address',
                'tenant_logo_path',
                'tenant_favicon_path',
                'tenant_currency_code',
                'tenant_currency_symbol',
                'tenant_default_language',
                'tenant_footer_text',
                'tenant_page_title_suffix',
                'tenant_developed_by',
            ]);
        });
    }
};
