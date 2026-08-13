<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('landing_terms_conditions')) {
            return;
        }

        Schema::connection('central')->create('landing_terms_conditions', function (Blueprint $table) {
            $table->id();
            $table->json('translations')->nullable();
            $table->text('acceptance')->nullable();
            $table->text('use_license')->nullable();
            $table->text('user_accounts')->nullable();
            $table->text('payments')->nullable();
            $table->text('prohibited')->nullable();
            $table->text('intellectual_property')->nullable();
            $table->text('liability')->nullable();
            $table->text('governing_law')->nullable();
            $table->date('last_updated')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_terms_conditions');
    }
};
