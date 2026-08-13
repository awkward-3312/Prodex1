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
        Schema::create('settings', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('email');
            $table->integer('currency_id')->nullable()->index('currency_id');
            $table->string('CompanyName');
            $table->string('company_name_ar')->nullable();
            $table->string('CompanyPhone');
            $table->string('CompanyAdress');
            $table->string('vat_number')->nullable();
            $table->string('logo')->nullable();
            $table->string('favicon')->nullable()->default('favicon.ico');
            $table->boolean('is_invoice_footer')->default(false);
            $table->string('invoice_footer', 192)->nullable();
            $table->string('invoice_format', 20)->default('thermal');
            $table->string('footer', 192)->default('Stocky - Ultimate Inventory With POS');
            $table->string('developed_by', 192)->default('Stocky');
            $table->integer('client_id')->nullable()->index('client_id');
            $table->integer('warehouse_id')->nullable()->index('warehouse_id');
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->unsignedBigInteger('default_payment_method_id')->nullable();
            $table->string('default_language', 192)->default('en');
            $table->float('point_to_amount_rate')->default(1);
            $table->decimal('default_tax', 10)->default(0);
            $table->string('default_dashboard_date_range', 20)->default('week');
            $table->text('dashboard_section_order')->nullable();
            $table->text('dashboard_grid_layout')->nullable();
            $table->string('dashboard_font_size', 20)->nullable();
            $table->string('dashboard_font_family')->nullable();
            $table->string('sale_prefix', 10)->nullable()->default('SL');
            $table->string('purchase_prefix', 10)->nullable()->default('PR');
            $table->string('quotation_prefix', 10)->nullable()->default('QT');
            $table->string('adjustment_prefix', 10)->nullable()->default('AD');
            $table->string('transfer_prefix', 10)->nullable()->default('TR');
            $table->string('sale_return_prefix', 10)->nullable()->default('RT');
            $table->string('purchase_return_prefix', 10)->nullable()->default('RT');
            $table->boolean('backup_cloud_enabled')->default(false);
            $table->string('backup_cloud_provider', 50)->nullable();
            $table->string('backup_cloud_path', 255)->nullable();
            $table->string('backup_s3_bucket')->nullable();
            $table->string('backup_s3_region')->nullable();
            $table->string('backup_s3_access_key')->nullable();
            $table->text('backup_s3_secret_key')->nullable();
            $table->string('backup_s3_endpoint', 255)->nullable();
            $table->boolean('backup_s3_path_style')->default(false);
            $table->string('backup_gdrive_folder_id')->nullable();
            $table->text('backup_gdrive_access_token')->nullable();
            $table->text('backup_gdrive_refresh_token')->nullable();
            $table->string('backup_gdrive_client_id')->nullable();
            $table->text('backup_gdrive_client_secret')->nullable();
            $table->string('backup_dropbox_path')->nullable();
            $table->text('backup_dropbox_access_token')->nullable();
            $table->text('google_calendar_refresh_token')->nullable();
            $table->string('google_calendar_calendar_id', 255)->nullable();
            $table->string('google_calendar_client_id', 255)->nullable();
            $table->text('google_calendar_client_secret')->nullable();
            $table->string('google_calendar_redirect_uri', 512)->nullable();
            $table->boolean('backup_keep_local')->default(true);
            $table->integer('sms_gateway')->nullable()->default(1);
            $table->boolean('show_language')->default(true);
            $table->string('date_format', 20)->nullable()->default('YYYY-MM-DD');
            $table->string('price_format', 50)->nullable();
            $table->boolean('dark_mode')->default(false);
            $table->boolean('rtl')->default(false);
            $table->boolean('zatca_enabled')->default(false);
            $table->boolean('quotation_with_stock')->default(true);
            $table->string('app_name')->nullable()->default('Stocky | Ultimate Inventory With POS');
            $table->string('page_title_suffix')->nullable()->default('Ultimate Inventory With POS');
            $table->string('login_hero_title')->nullable()->default('Welcome back!');
            $table->string('login_hero_subtitle')->nullable()->default('Sign in to access your account and keep your operations in sync.');
            $table->string('login_panel_title')->nullable()->default('Sign In');
            $table->string('login_panel_subtitle')->nullable()->default('Access your dashboard and manage everything from one place.');
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
