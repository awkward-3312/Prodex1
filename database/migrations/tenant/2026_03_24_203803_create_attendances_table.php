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
        Schema::create('attendances', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('attendances_user_id');
            $table->integer('company_id')->index('attendances_company_id');
            $table->integer('employee_id')->index('attendances_employee_id');
            $table->date('date');
            $table->string('clock_in');
            $table->string('clock_in_ip', 45);
            $table->string('clock_out');
            $table->string('clock_out_ip');
            $table->boolean('clock_in_out');
            $table->string('depart_early')->default('00:00');
            $table->string('late_time')->default('00:00');
            $table->string('overtime')->default('00:00');
            $table->string('total_work')->default('00:00');
            $table->string('total_rest')->default('00:00');
            $table->string('status')->default('present');
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
