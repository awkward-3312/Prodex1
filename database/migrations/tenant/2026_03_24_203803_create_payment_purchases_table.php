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
        Schema::create('payment_purchases', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('user_id_payment_purchases');
            $table->date('date');
            $table->string('Ref', 192);
            $table->integer('purchase_id')->index('payments_purchase_id');
            $table->integer('account_id')->nullable()->index('payment_purchases_account_id');
            $table->decimal('montant', 15);
            $table->decimal('change', 15)->default(0);
            $table->integer('payment_method_id')->nullable()->index('payment_purchases_payment_method_id_foreign');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_purchases');
    }
};
