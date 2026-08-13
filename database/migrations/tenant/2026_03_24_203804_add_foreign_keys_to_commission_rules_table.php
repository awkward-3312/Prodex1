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
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->foreign(['commission_program_id'])->references(['id'])->on('commission_programs')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['sales_agent_id'])->references(['id'])->on('sales_agents')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropForeign('commission_rules_commission_program_id_foreign');
            $table->dropForeign('commission_rules_sales_agent_id_foreign');
        });
    }
};
