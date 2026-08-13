<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecruitCandidatesTable extends Migration
{
    public function up()
    {
        Schema::create('recruit_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('current_company')->nullable();
            $table->string('current_position')->nullable();
            $table->decimal('current_salary', 15, 2)->nullable();
            $table->decimal('expected_salary', 15, 2)->nullable();
            $table->integer('experience_years')->nullable();
            $table->text('skills')->nullable();
            $table->text('education')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('photo')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->text('notes')->nullable();
            $table->enum('source', ['website', 'referral', 'linkedin', 'job_board', 'agency', 'walk_in', 'other'])->default('website');
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('recruit_candidates');
    }
}
