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
        Schema::table('contract_renewals', function (Blueprint $table) {
            $table->foreign(['contract_id'])->references(['id'])->on('contracts')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['renewed_from_contract_id'])->references(['id'])->on('contracts')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_renewals', function (Blueprint $table) {
            $table->dropForeign('contract_renewals_contract_id_foreign');
            $table->dropForeign('contract_renewals_renewed_from_contract_id_foreign');
        });
    }
};
