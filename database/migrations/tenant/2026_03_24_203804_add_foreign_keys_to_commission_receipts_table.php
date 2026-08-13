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
        Schema::table('commission_receipts', function (Blueprint $table) {
            $table->foreign(['payment_method_id'])->references(['id'])->on('payment_methods')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['sales_agent_id'])->references(['id'])->on('sales_agents')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commission_receipts', function (Blueprint $table) {
            $table->dropForeign('commission_receipts_payment_method_id_foreign');
            $table->dropForeign('commission_receipts_sales_agent_id_foreign');
        });
    }
};
