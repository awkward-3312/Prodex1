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
        Schema::table('client_opening_balance_payments', function (Blueprint $table) {
            $table->foreign(['account_id'])->references(['id'])->on('accounts')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['payment_method_id'])->references(['id'])->on('payment_methods')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_opening_balance_payments', function (Blueprint $table) {
            $table->dropForeign('client_opening_balance_payments_account_id_foreign');
            $table->dropForeign('client_opening_balance_payments_client_id_foreign');
            $table->dropForeign('client_opening_balance_payments_payment_method_id_foreign');
            $table->dropForeign('client_opening_balance_payments_user_id_foreign');
        });
    }
};
