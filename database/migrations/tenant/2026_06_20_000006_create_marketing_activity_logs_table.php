<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('marketing_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module');            // campaign, segment, template, settings
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('action');            // created, updated, deleted, sent, ...
            $table->string('description')->nullable();
            $table->timestamps(6);

            $table->index(['module', 'reference_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_activity_logs');
    }
};
