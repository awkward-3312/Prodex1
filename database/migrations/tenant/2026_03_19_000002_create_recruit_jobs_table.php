<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecruitJobsTable extends Migration
{
    public function up()
    {
        Schema::create('recruit_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->enum('job_type', ['full_time', 'part_time', 'contract', 'internship', 'remote'])->default('full_time');
            $table->string('location')->nullable();
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->text('benefits')->nullable();
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->integer('vacancies')->default(1);
            $table->enum('status', ['draft', 'open', 'on_hold', 'closed'])->default('draft');
            $table->enum('experience_level', ['entry', 'mid', 'senior', 'lead', 'manager'])->default('entry');
            $table->date('deadline')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('recruit_job_categories')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('recruit_jobs');
    }
}
