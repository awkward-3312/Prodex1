<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecruitInterviewsTable extends Migration
{
    public function up()
    {
        Schema::create('recruit_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->enum('type', ['phone', 'video', 'in_person', 'technical', 'panel', 'group'])->default('in_person');
            $table->datetime('scheduled_at');
            $table->integer('duration_minutes')->default(60);
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->unsignedBigInteger('interviewer_id')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'no_show', 'rescheduled'])->default('scheduled');
            $table->integer('rating')->nullable();
            $table->text('feedback')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->foreign('application_id')->references('id')->on('recruit_applications')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('recruit_interviews');
    }
}
