<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('meeting_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('description')->nullable();
            $table->timestamps(6);

            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
            $table->index('meeting_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meeting_activity_logs');
    }
};
