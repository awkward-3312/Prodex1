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
        Schema::create('sales', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->char('sale_uuid', 36)->nullable()->unique();
            $table->unsignedBigInteger('woocommerce_order_id')->nullable();
            $table->string('woocommerce_order_number', 64)->nullable();
            $table->string('woocommerce_order_status', 32)->nullable();
            $table->integer('user_id')->index('user_id_sales');
            $table->unsignedInteger('sales_agent_id')->nullable()->index();
            $table->date('date');
            $table->time('time')->nullable();
            $table->string('Ref', 192);
            $table->boolean('is_pos')->nullable()->default(false);
            $table->integer('client_id')->index('sale_client_id');
            $table->integer('warehouse_id')->index('warehouse_id_sale');
            $table->integer('subscription_id')->nullable()->index('subscription_id');
            $table->decimal('tax_rate', 15)->nullable()->default(0);
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_Method', 10)->default('2');
            $table->decimal('shipping', 15)->nullable()->default(0);
            $table->decimal('GrandTotal', 15)->default(0);
            $table->decimal('paid_amount', 15)->default(0);
            $table->string('payment_statut', 192);
            $table->string('statut');
            $table->string('shipping_status')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('used_points', 15)->default(0);
            $table->decimal('earned_points', 15)->default(0);
            $table->decimal('discount_from_points', 15)->default(0);
            $table->string('quickbooks_invoice_id')->nullable()->index();
            $table->string('quickbooks_realm_id')->nullable()->index();
            $table->timestamp('quickbooks_synced_at')->nullable();
            $table->string('quickbooks_sync_error', 2048)->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->unique(['woocommerce_order_id', 'deleted_at'], 'sales_woo_order_id_deleted_at_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
