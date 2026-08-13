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
        Schema::table('sale_commissions', function (Blueprint $table) {
            $table->foreign(['commission_program_id'])->references(['id'])->on('commission_programs')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['commission_receipt_id'])->references(['id'])->on('commission_receipts')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['commission_rule_id'])->references(['id'])->on('commission_rules')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['sales_agent_id'])->references(['id'])->on('sales_agents')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['sale_id'])->references(['id'])->on('sales')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_commissions', function (Blueprint $table) {
            $table->dropForeign('sale_commissions_commission_program_id_foreign');
            $table->dropForeign('sale_commissions_commission_receipt_id_foreign');
            $table->dropForeign('sale_commissions_commission_rule_id_foreign');
            $table->dropForeign('sale_commissions_sales_agent_id_foreign');
            $table->dropForeign('sale_commissions_sale_id_foreign');
        });
    }
};
