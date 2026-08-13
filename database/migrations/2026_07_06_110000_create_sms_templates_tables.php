<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsTemplatesTables extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_key')->unique();
            $table->string('name');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('central')->create('sms_template_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_template_id')->constrained('sms_templates')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('body');
            $table->timestamps();

            $table->unique(['sms_template_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sms_template_translations');
        Schema::connection('central')->dropIfExists('sms_templates');
    }
}
