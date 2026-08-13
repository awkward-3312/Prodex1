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
        Schema::table('employee_experiences', function (Blueprint $table) {
            $table->foreign(['employee_id'])->references(['id'])->on('employees')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_experiences', function (Blueprint $table) {
            $table->dropForeign('employee_experiences_employee_id_foreign');
        });
    }
};
