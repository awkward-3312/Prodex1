<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('email_template_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained('email_templates')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('subject');
            $table->longText('body_html');
            $table->timestamps();

            $table->unique(['email_template_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('email_template_translations');
    }
};
