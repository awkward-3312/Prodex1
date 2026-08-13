<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLandingFeaturesTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('landing_features_section', function (Blueprint $table) {
            $table->id();
            $table->string('section_title')->nullable();
            $table->string('section_subtitle')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('central')->create('landing_features', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_features');
        Schema::connection('central')->dropIfExists('landing_features_section');
    }
}
