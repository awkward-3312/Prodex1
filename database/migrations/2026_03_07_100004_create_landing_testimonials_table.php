<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLandingTestimonialsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('landing_testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('company_name')->nullable();
            $table->text('review');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->string('avatar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_testimonials');
    }
}
