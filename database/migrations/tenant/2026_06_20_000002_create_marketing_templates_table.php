<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('marketing_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['sms', 'email', 'whatsapp'])->default('sms');
            $table->string('category')->nullable();
            $table->string('subject')->nullable();
            $table->text('content');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();

            $table->index('type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_templates');
    }
};
