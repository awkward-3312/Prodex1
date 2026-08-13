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
        Schema::table('contract_attachments', function (Blueprint $table) {
            $table->foreign(['contract_id'])->references(['id'])->on('contracts')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_attachments', function (Blueprint $table) {
            $table->dropForeign('contract_attachments_contract_id_foreign');
        });
    }
};
