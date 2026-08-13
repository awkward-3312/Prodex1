<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('marketing_settings', function (Blueprint $table) {
            $table->id();

            // SMS gateway (generic configurable HTTP provider)
            $table->boolean('sms_enabled')->default(false);
            $table->string('sms_provider')->nullable();        // free-text label
            $table->string('sms_api_url')->nullable();
            $table->string('sms_api_key')->nullable();
            $table->string('sms_api_secret')->nullable();
            $table->string('sms_sender_id')->nullable();
            $table->string('sms_http_method')->default('POST'); // POST or GET
            $table->json('sms_extra_params')->nullable();       // additional static params
            // Field-name mapping so the generic adapter knows which keys to send
            $table->string('sms_to_field')->default('to');
            $table->string('sms_message_field')->default('message');

            // WhatsApp API (generic configurable HTTP provider)
            $table->boolean('whatsapp_enabled')->default(false);
            $table->string('whatsapp_provider')->nullable();
            $table->string('whatsapp_api_url')->nullable();
            $table->string('whatsapp_api_key')->nullable();
            $table->string('whatsapp_phone_id')->nullable();
            $table->string('whatsapp_http_method')->default('POST');
            $table->json('whatsapp_extra_params')->nullable();
            $table->string('whatsapp_to_field')->default('to');
            $table->string('whatsapp_message_field')->default('message');

            // Email (uses Stocky's existing SMTP / mail config)
            $table->boolean('email_enabled')->default(true);
            $table->string('email_from_name')->nullable();
            $table->string('email_from_address')->nullable();

            // Default sender information
            $table->string('default_sender_name')->nullable();

            // Scheduling
            $table->boolean('scheduling_enabled')->default(true);
            $table->integer('batch_size')->default(100);

            $table->timestamps(6);
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_settings');
    }
};
