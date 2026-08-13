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
        Schema::table('payment_sale_returns', function (Blueprint $table) {
            $table->foreign(['sale_return_id'])->references(['id'])->on('sale_returns')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['account_id'])->references(['id'])->on('accounts')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['payment_method_id'])->references(['id'])->on('payment_methods')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_sale_returns', function (Blueprint $table) {
            $table->dropForeign('payment_sale_returns_sale_return_id_foreign');
            $table->dropForeign('payment_sale_returns_user_id_foreign');
            $table->dropForeign('payment_sale_returns_account_id_foreign');
            $table->dropForeign('payment_sale_returns_payment_method_id_foreign');
        });
    }
};
