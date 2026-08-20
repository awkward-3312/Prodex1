<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_employee_identifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('employee_id')->index();
            $table->unsignedBigInteger('attendance_device_id')->nullable()->index();
            $table->string('provider', 80)->default('generic')->index();
            $table->string('external_user_id', 191)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->cascadeOnUpdate()->nullOnDelete();

            $table->unique(['attendance_device_id', 'external_user_id'], 'attendance_identifier_device_user_unique');
            $table->index(['company_id', 'provider', 'external_user_id'], 'attendance_identifier_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_employee_identifiers');
    }
};
