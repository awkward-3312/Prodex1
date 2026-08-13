<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecruitJobCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('recruit_job_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('recruit_job_categories');
    }
}
