<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLandingPrivacyPolicyTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('landing_privacy_policy', function (Blueprint $table) {
            $table->id();
            $table->json('translations')->nullable();
            $table->text('introduction')->nullable();
            $table->text('data_collection')->nullable();
            $table->text('data_usage')->nullable();
            $table->text('cookies_usage')->nullable();
            $table->text('third_party')->nullable();
            $table->text('data_protection')->nullable();
            $table->text('user_rights')->nullable();
            $table->text('contact_info')->nullable();
            $table->date('last_updated')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_privacy_policy');
    }
}
