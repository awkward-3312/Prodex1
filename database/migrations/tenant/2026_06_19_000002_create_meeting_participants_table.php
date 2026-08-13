<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('invitation_status', ['invited', 'accepted', 'declined', 'tentative'])->default('invited');
            $table->enum('attendance_status', ['pending', 'present', 'absent', 'late'])->default('pending');
            $table->boolean('is_notified')->default(false);
            $table->datetime('notified_at')->nullable();
            $table->timestamps(6);

            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('meeting_participants');
    }
};
