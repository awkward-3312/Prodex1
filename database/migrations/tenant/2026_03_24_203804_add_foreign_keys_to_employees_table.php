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
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign(['company_id'])->references(['id'])->on('companies')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['department_id'])->references(['id'])->on('departments')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['designation_id'])->references(['id'])->on('designations')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['office_shift_id'])->references(['id'])->on('office_shifts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('employees_company_id_foreign');
            $table->dropForeign('employees_department_id_foreign');
            $table->dropForeign('employees_designation_id_foreign');
            $table->dropForeign('employees_office_shift_id_foreign');
        });
    }
};
