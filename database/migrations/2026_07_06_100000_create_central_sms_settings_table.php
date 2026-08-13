<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCentralSmsSettingsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('sms_gateway')->nullable();

            // Twilio
            $table->string('twilio_sid')->nullable();
            $table->text('twilio_token')->nullable();
            $table->string('twilio_from')->nullable();

            // Termii
            $table->text('termii_api_key')->nullable();
            $table->text('termii_secret')->nullable();
            $table->string('termii_sender')->nullable();

            // Infobip
            $table->string('infobip_base_url')->nullable();
            $table->text('infobip_api_key')->nullable();
            $table->string('infobip_sender_from')->nullable();

            // Custom HTTP gateway
            $table->string('custom_api_url', 1000)->nullable();
            $table->string('custom_method', 10)->nullable()->default('POST');
            $table->string('custom_content_type', 20)->nullable()->default('json');
            $table->string('custom_sender')->nullable();
            $table->string('custom_success_keyword')->nullable();
            $table->json('custom_headers')->nullable();
            $table->json('custom_payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sms_settings');
    }
}
