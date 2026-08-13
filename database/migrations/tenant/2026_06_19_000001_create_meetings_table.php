<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('agenda')->nullable();
            $table->date('meeting_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('location')->nullable();
            $table->enum('type', ['physical', 'online'])->default('physical');
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            $table->enum('platform', ['zoom', 'google_meet', 'teams', 'other'])->nullable();
            $table->string('meeting_link')->nullable();
            $table->integer('reminder_minutes')->default(30);
            $table->boolean('reminder_sent')->default(false);
            $table->unsignedBigInteger('organizer_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->index(['meeting_date', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('meetings');
    }
};
