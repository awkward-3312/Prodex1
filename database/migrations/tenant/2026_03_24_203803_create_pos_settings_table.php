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
        Schema::create('pos_settings', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('note_customer', 192)->default('Thank You For Shopping With Us . Please Come Again');
            $table->boolean('show_logo')->default(true);
            $table->integer('logo_size')->default(60);
            $table->boolean('show_store_name')->default(true);
            $table->boolean('show_reference')->default(true);
            $table->boolean('show_date')->default(true);
            $table->boolean('show_seller')->default(true);
            $table->boolean('show_note')->default(true);
            $table->boolean('show_barcode')->default(true);
            $table->boolean('show_discount')->default(true);
            $table->boolean('show_tax')->default(true);
            $table->boolean('show_shipping')->default(true);
            $table->boolean('show_paid')->default(true);
            $table->boolean('show_due')->default(true);
            $table->boolean('show_payments')->default(true);
            $table->boolean('show_zatca_qr')->default(true);
            $table->boolean('cash_drawer_auto_open')->default(false);
            $table->string('cash_drawer_printer_name', 192)->nullable();
            $table->boolean('show_customer')->default(true);
            $table->boolean('show_email')->default(true);
            $table->boolean('show_phone')->default(true);
            $table->boolean('show_address')->default(true);
            $table->integer('products_per_page')->default(10);
            $table->boolean('quick_add_customer')->default(true);
            $table->boolean('barcode_scanning_sound')->default(true);
            $table->boolean('show_product_images')->default(true);
            $table->boolean('show_stock_quantity')->default(true);
            $table->boolean('enable_hold_sales')->default(true);
            $table->boolean('enable_customer_points')->default(true);
            $table->boolean('show_categories')->default(true);
            $table->boolean('show_brands')->default(true);
            $table->unsignedTinyInteger('receipt_layout')->default(1);
            $table->unsignedSmallInteger('receipt_paper_size')->default(80);
            $table->boolean('is_printable')->default(true);
            $table->timestamps(6);
            $table->softDeletes();
            $table->boolean('show_Warehouse')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_settings');
    }
};
