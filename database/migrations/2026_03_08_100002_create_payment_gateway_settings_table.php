<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentGatewaySettingsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gateway')->unique();
            $table->boolean('is_active')->default(false);
            $table->boolean('test_mode')->default(true);
            $table->json('credentials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('payment_gateway_settings');
    }
}
