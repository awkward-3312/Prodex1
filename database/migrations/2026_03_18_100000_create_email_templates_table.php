<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmailTemplatesTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('body_html');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('email_templates');
    }
}
