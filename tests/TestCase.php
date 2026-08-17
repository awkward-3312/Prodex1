<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    /**
     * Override refreshTestDatabase to setup only necessary tables for testing.
     * We skip full migrations and create only what's needed.
     */
    protected function refreshTestDatabase(): void
    {
        // Clear all existing tables
        DB::statement('PRAGMA foreign_keys = OFF');
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table->name}`");
        }
        DB::statement('PRAGMA foreign_keys = ON');

        // Manually create the migrations table so Laravel knows which migrations ran
        Schema::create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });

        // Create only the settings table needed for tests
        Schema::create('settings', function ($table) {
            $table->integer('id', true);
            $table->string('email')->nullable();
            $table->integer('currency_id')->nullable();
            $table->string('CompanyName')->nullable();
            $table->string('company_name_ar')->nullable();
            $table->string('CompanyPhone')->nullable();
            $table->string('CompanyAdress')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('logo')->nullable();
            $table->string('favicon')->nullable();
            $table->boolean('is_invoice_footer')->default(false);
            $table->string('invoice_footer')->nullable();
            $table->string('invoice_format')->default('thermal');
            $table->string('footer')->default('Stocky');
            $table->string('developed_by')->default('Stocky');
            $table->integer('client_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->unsignedBigInteger('default_payment_method_id')->nullable();
            $table->string('default_language')->default('en');
            $table->boolean('show_language')->default(1);
            $table->boolean('quotation_with_stock')->default(1);
            $table->boolean('customize_button_visible')->default(1);
            $table->boolean('hide_site_name')->default(0);
            $table->string('country_code', 3)->nullable()->default('HN');
            $table->string('tax_regime_code', 20)->nullable()->default('SAR');
            $table->decimal('tax_rate', 10, 2)->nullable()->default(15.00);
            $table->string('locale', 20)->nullable()->default('es-HN');
            $table->string('legal_document_label', 50)->nullable()->default('RTN');
            $table->boolean('require_rtn')->default(true);
            $table->boolean('require_rfc')->default(false);
            $table->boolean('require_nit')->default(false);
            $table->float('point_to_amount_rate')->default(1);
            $table->decimal('default_tax', 10)->default(0);
            $table->string('default_dashboard_date_range', 20)->default('week');
            $table->text('dashboard_section_order')->nullable();
            $table->text('dashboard_grid_layout')->nullable();
            $table->string('dashboard_font_size', 20)->nullable();
            $table->string('dashboard_font_family')->nullable();
            $table->string('app_name')->nullable()->default('Prodex');
            $table->string('timezone')->nullable()->default('UTC');
            $table->timestamps();
        });

        Schema::connection('central')->create('general_settings', function ($table) {
            $table->id();
            $table->string('app_name')->default('Prodex');
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('landing_template', 32)->default('landing-two');
            $table->string('landing_font', 100)->nullable();
            $table->string('landing_heading_font', 100)->nullable();
            $table->text('dashboard_footer_text')->nullable();
            $table->timestamps();
        });

        Schema::connection('central')->create('plans', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('yearly_price', 12, 2)->nullable();
            $table->string('billing_interval')->default('monthly');
            $table->json('limits')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_private')->default(false);
            $table->boolean('is_trial')->default(false);
            $table->unsignedInteger('trial_days')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('central_languages', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('locale', 10)->unique();
            $table->string('flag', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_rtl')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Record that we've run this "migration" so it doesn't try to run again
        DB::table('migrations')->insert([
            'migration' => 'test_settings_table',
            'batch' => 1,
        ]);
    }
}
