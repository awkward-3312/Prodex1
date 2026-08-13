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
        Schema::create('tasks', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('title', 192);
            $table->integer('project_id')->index('tasks_project_id');
            $table->integer('company_id')->index('tasks_company_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('estimated_hour', 192)->nullable();
            $table->string('task_progress', 192)->nullable();
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('status', 192);
            $table->string('priority');
            $table->text('note')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
