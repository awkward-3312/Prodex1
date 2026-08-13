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
        Schema::table('contract_notes', function (Blueprint $table) {
            $table->foreign(['contract_id'])->references(['id'])->on('contracts')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_notes', function (Blueprint $table) {
            $table->dropForeign('contract_notes_contract_id_foreign');
            $table->dropForeign('contract_notes_user_id_foreign');
        });
    }
};
