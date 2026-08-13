<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLandingPricingTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('landing_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('section_title')->nullable();
            $table->string('section_subtitle')->nullable();
            $table->boolean('show_monthly_pricing')->default(true);
            $table->boolean('show_yearly_pricing')->default(true);
            $table->boolean('load_plans_from_database')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_pricing');
    }
}
