<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLandingStatsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('landing_stats', function (Blueprint $table) {
            $table->id();
            $table->json('translations')->nullable();
            $table->string('value');
            $table->string('label');
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_stats');
    }
}
