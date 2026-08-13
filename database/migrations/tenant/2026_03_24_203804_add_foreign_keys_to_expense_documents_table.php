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
        Schema::table('expense_documents', function (Blueprint $table) {
            $table->foreign(['expense_id'])->references(['id'])->on('expenses')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_documents', function (Blueprint $table) {
            $table->dropForeign('expense_documents_expense_id_foreign');
        });
    }
};
