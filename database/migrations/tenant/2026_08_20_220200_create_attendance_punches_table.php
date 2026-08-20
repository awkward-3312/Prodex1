<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id')->index();
            $table->integer('employee_id')->nullable()->index();
            $table->unsignedBigInteger('attendance_employee_identifier_id')->nullable()->index();
            $table->unsignedBigInteger('attendance_device_id')->nullable()->index();
            $table->string('provider', 80)->default('generic')->index();
            $table->string('external_user_id', 191)->index();
            $table->dateTime('occurred_at')->index();
            $table->string('punch_type', 40)->nullable();
            $table->string('verification_method', 80)->nullable();
            $table->string('source', 40)->default('import')->index();
            $table->string('source_reference')->nullable();
            $table->char('source_fingerprint', 64)->unique();
            $table->string('processing_status', 40)->default('pending')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('attendance_employee_identifier_id')->references('id')->on('attendance_employee_identifiers')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
