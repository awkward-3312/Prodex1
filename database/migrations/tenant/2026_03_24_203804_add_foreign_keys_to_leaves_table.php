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
        Schema::table('leaves', function (Blueprint $table) {
            $table->foreign(['company_id'])->references(['id'])->on('companies')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['department_id'])->references(['id'])->on('departments')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['employee_id'])->references(['id'])->on('employees')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['leave_type_id'])->references(['id'])->on('leave_types')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropForeign('leaves_company_id_foreign');
            $table->dropForeign('leaves_department_id_foreign');
            $table->dropForeign('leaves_employee_id_foreign');
            $table->dropForeign('leaves_leave_type_id_foreign');
        });
    }
};
