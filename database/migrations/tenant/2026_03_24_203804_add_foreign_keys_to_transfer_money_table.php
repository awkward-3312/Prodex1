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
        Schema::table('transfer_money', function (Blueprint $table) {
            $table->foreign(['from_account_id'])->references(['id'])->on('accounts')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['to_account_id'])->references(['id'])->on('accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfer_money', function (Blueprint $table) {
            $table->dropForeign('transfer_money_from_account_id_foreign');
            $table->dropForeign('transfer_money_to_account_id_foreign');
        });
    }
};
