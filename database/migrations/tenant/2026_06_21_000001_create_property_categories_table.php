<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
            $table->index('slug');
        });

        // Seed the default real-estate property categories (idempotent).
        $defaults = [
            'Apartment', 'House', 'Villa', 'Office', 'Commercial Property', 'Land',
        ];
        foreach ($defaults as $name) {
            $slug = \Illuminate\Support\Str::slug($name);
            $exists = DB::table('property_categories')->where('slug', $slug)->exists();
            if (! $exists) {
                DB::table('property_categories')->insert([
                    'name'       => $name,
                    'slug'       => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_categories');
    }
};
