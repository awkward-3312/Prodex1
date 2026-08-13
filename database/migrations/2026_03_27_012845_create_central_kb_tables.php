<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('kb_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_category_id')->constrained('kb_categories')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kb_category_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('kb_articles');
        Schema::connection('central')->dropIfExists('kb_categories');
    }
};
