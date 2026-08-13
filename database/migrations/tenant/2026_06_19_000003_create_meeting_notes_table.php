<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('meeting_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_id');
            $table->enum('type', ['note', 'decision', 'action_item'])->default('note');
            $table->text('content');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', ['open', 'in_progress', 'done'])->default('open');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('meeting_notes');
    }
};
