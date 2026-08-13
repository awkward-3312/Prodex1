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
        Schema::create('payment_sale_returns', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('factures_sale_return_user_id');
            $table->date('date');
            $table->string('Ref', 192);
            $table->integer('sale_return_id')->index('factures_sale_return');
            $table->integer('account_id')->nullable()->index('payment_sale_returns_account_id');
            $table->decimal('montant', 15);
            $table->decimal('change', 15)->default(0);
            $table->integer('payment_method_id')->nullable()->index('payment_sale_returns_payment_method_id_foreign');
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
        Schema::dropIfExists('payment_sale_returns');
    }
};
