<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('central_languages', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // English, French, Arabic …
            $table->string('locale', 10)->unique(); // en, fr, ar …
            $table->string('flag', 50)->nullable();  // SVG filename: gb.svg
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_rtl')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('central_languages');
    }
};
