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
        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->foreign(['purchase_id'])->references(['id'])->on('purchases')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['account_id'])->references(['id'])->on('accounts')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['payment_method_id'])->references(['id'])->on('payment_methods')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_purchases', function (Blueprint $table) {
            $table->dropForeign('payment_purchases_purchase_id_foreign');
            $table->dropForeign('payment_purchases_account_id_foreign');
            $table->dropForeign('payment_purchases_payment_method_id_foreign');
            $table->dropForeign('payment_purchases_user_id_foreign');
        });
    }
};
