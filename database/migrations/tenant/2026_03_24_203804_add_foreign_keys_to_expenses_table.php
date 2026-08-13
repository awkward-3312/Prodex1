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
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreign(['payment_method_id'])->references(['id'])->on('payment_methods')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['account_id'])->references(['id'])->on('accounts')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['expense_category_id'])->references(['id'])->on('expense_categories')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['warehouse_id'])->references(['id'])->on('warehouses')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign('expenses_payment_method_id_foreign');
            $table->dropForeign('expenses_account_id_foreign');
            $table->dropForeign('expenses_expense_category_id_foreign');
            $table->dropForeign('expenses_user_id_foreign');
            $table->dropForeign('expenses_warehouse_id_foreign');
        });
    }
};
