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
        Schema::create('office_shifts', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('company_id')->index('office_shift_company_id');
            $table->string('name');
            $table->string('monday_in')->nullable();
            $table->string('monday_out')->nullable();
            $table->string('tuesday_in')->nullable();
            $table->string('tuesday_out')->nullable();
            $table->string('wednesday_in')->nullable();
            $table->string('wednesday_out')->nullable();
            $table->string('thursday_in')->nullable();
            $table->string('thursday_out')->nullable();
            $table->string('friday_in')->nullable();
            $table->string('friday_out')->nullable();
            $table->string('saturday_in')->nullable();
            $table->string('saturday_out')->nullable();
            $table->string('sunday_in')->nullable();
            $table->string('sunday_out')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_shifts');
    }
};
