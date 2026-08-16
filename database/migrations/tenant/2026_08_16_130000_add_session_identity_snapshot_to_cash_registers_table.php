<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_registers', 'register_number_snapshot')) $table->string('register_number_snapshot')->nullable()->after('next_opening_float');
            if (! Schema::hasColumn('cash_registers', 'opened_by_user_id_snapshot')) $table->unsignedInteger('opened_by_user_id_snapshot')->nullable()->after('register_number_snapshot');
            if (! Schema::hasColumn('cash_registers', 'opened_by_user_name_snapshot')) $table->string('opened_by_user_name_snapshot')->nullable()->after('opened_by_user_id_snapshot');
            if (! Schema::hasColumn('cash_registers', 'closed_by_user_id')) $table->unsignedInteger('closed_by_user_id')->nullable()->after('opened_by_user_name_snapshot');
            if (! Schema::hasColumn('cash_registers', 'closed_by_user_name_snapshot')) $table->string('closed_by_user_name_snapshot')->nullable()->after('closed_by_user_id');
            if (! Schema::hasColumn('cash_registers', 'warehouse_id_snapshot')) $table->unsignedInteger('warehouse_id_snapshot')->nullable()->after('closed_by_user_name_snapshot');
            if (! Schema::hasColumn('cash_registers', 'warehouse_name_snapshot')) $table->string('warehouse_name_snapshot')->nullable()->after('warehouse_id_snapshot');
            if (! Schema::hasColumn('cash_registers', 'tenant_id_snapshot')) $table->string('tenant_id_snapshot')->nullable()->after('warehouse_name_snapshot');
            if (! Schema::hasColumn('cash_registers', 'opened_date_snapshot')) $table->date('opened_date_snapshot')->nullable()->after('tenant_id_snapshot');
            if (! Schema::hasColumn('cash_registers', 'opened_time_snapshot')) $table->time('opened_time_snapshot')->nullable()->after('opened_date_snapshot');
            if (! Schema::hasColumn('cash_registers', 'closed_date_snapshot')) $table->date('closed_date_snapshot')->nullable()->after('opened_time_snapshot');
            if (! Schema::hasColumn('cash_registers', 'closed_time_snapshot')) $table->time('closed_time_snapshot')->nullable()->after('closed_date_snapshot');
            if (! Schema::hasColumn('cash_registers', 'session_duration_seconds')) $table->unsignedInteger('session_duration_seconds')->nullable()->after('closed_time_snapshot');
            if (! Schema::hasColumn('cash_registers', 'closing_status')) $table->string('closing_status', 20)->nullable()->after('session_duration_seconds');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('cash_registers', 'register_number_snapshot') ? 'register_number_snapshot' : null,
            Schema::hasColumn('cash_registers', 'opened_by_user_id_snapshot') ? 'opened_by_user_id_snapshot' : null,
            Schema::hasColumn('cash_registers', 'opened_by_user_name_snapshot') ? 'opened_by_user_name_snapshot' : null,
            Schema::hasColumn('cash_registers', 'closed_by_user_id') ? 'closed_by_user_id' : null,
            Schema::hasColumn('cash_registers', 'closed_by_user_name_snapshot') ? 'closed_by_user_name_snapshot' : null,
            Schema::hasColumn('cash_registers', 'warehouse_id_snapshot') ? 'warehouse_id_snapshot' : null,
            Schema::hasColumn('cash_registers', 'warehouse_name_snapshot') ? 'warehouse_name_snapshot' : null,
            Schema::hasColumn('cash_registers', 'tenant_id_snapshot') ? 'tenant_id_snapshot' : null,
            Schema::hasColumn('cash_registers', 'opened_date_snapshot') ? 'opened_date_snapshot' : null,
            Schema::hasColumn('cash_registers', 'opened_time_snapshot') ? 'opened_time_snapshot' : null,
            Schema::hasColumn('cash_registers', 'closed_date_snapshot') ? 'closed_date_snapshot' : null,
            Schema::hasColumn('cash_registers', 'closed_time_snapshot') ? 'closed_time_snapshot' : null,
            Schema::hasColumn('cash_registers', 'session_duration_seconds') ? 'session_duration_seconds' : null,
            Schema::hasColumn('cash_registers', 'closing_status') ? 'closing_status' : null,
        ]));

        if (empty($columns)) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
