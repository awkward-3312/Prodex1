<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecruitApplicationsTable extends Migration
{
    public function up()
    {
        Schema::create('recruit_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('candidate_id');
            $table->enum('stage', ['applied', 'screening', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'])->default('applied');
            $table->date('applied_date');
            $table->text('cover_letter')->nullable();
            $table->integer('rating')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->foreign('job_id')->references('id')->on('recruit_jobs')->cascadeOnDelete();
            $table->foreign('candidate_id')->references('id')->on('recruit_candidates')->cascadeOnDelete();
            $table->unique(['job_id', 'candidate_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('recruit_applications');
    }
}
