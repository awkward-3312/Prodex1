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
        Schema::table('sales', function (Blueprint $table) {
            $table->foreign(['sales_agent_id'])->references(['id'])->on('sales_agents')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['subscription_id'])->references(['id'])->on('subscriptions')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['warehouse_id'])->references(['id'])->on('warehouses')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign('sales_sales_agent_id_foreign');
            $table->dropForeign('sales_subscription_id_foreign');
            $table->dropForeign('sales_client_id_foreign');
            $table->dropForeign('sales_user_id_foreign');
            $table->dropForeign('sales_warehouse_id_foreign');
        });
    }
};
