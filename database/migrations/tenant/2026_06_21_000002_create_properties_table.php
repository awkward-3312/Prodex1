<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('property_category_id')->nullable();
            $table->text('description')->nullable();

            // Listing intent & state
            $table->enum('purpose', ['sale', 'rent'])->default('sale');
            $table->enum('status', ['available', 'sold', 'rented'])->default('available');
            $table->boolean('featured')->default(false);

            // Specifications
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('area', 12, 2)->nullable();
            $table->string('area_unit', 12)->default('m²');
            $table->unsignedInteger('bedrooms')->nullable();
            $table->unsignedInteger('bathrooms')->nullable();
            $table->unsignedInteger('garage')->nullable();

            // Location
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Media: featured_image is a public-relative path, gallery is a JSON array of paths
            $table->string('featured_image')->nullable();
            $table->json('gallery')->nullable();

            // Features / amenities (JSON array of strings)
            $table->json('amenities')->nullable();

            // Agent details (per property)
            $table->string('agent_name')->nullable();
            $table->string('agent_phone')->nullable();
            $table->string('agent_email')->nullable();
            $table->string('agent_whatsapp')->nullable();

            // SEO
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('seo_keywords')->nullable();

            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps(6);
            $table->softDeletes();

            $table->index(['purpose', 'status']);
            $table->index('property_category_id');
            $table->index('featured');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
