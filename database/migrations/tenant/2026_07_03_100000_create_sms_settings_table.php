<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateSmsSettingsTable extends Migration
{
    public function up()
    {
        Schema::create('sms_settings', function (Blueprint $table) {
            $table->increments('id');

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
            $table->string('custom_api_url', 2048)->nullable();
            $table->string('custom_method', 10)->default('POST');
            $table->string('custom_content_type', 10)->default('json');
            $table->string('custom_sender', 64)->nullable();
            $table->string('custom_success_keyword', 128)->nullable();
            $table->text('custom_headers')->nullable();
            $table->text('custom_payload')->nullable();

            $table->timestamps();
        });

        // Until now every tenant shared the credentials stored in the central
        // .env, so copy those into the tenant row to keep the tenant that was
        // actively using SMS working after the switch to per-tenant storage.
        // env() returns null here when config is cached — the row is still
        // created and the tenant just re-saves its credentials from the UI.
        DB::table('sms_settings')->insert([
            'twilio_sid' => env('TWILIO_SID'),
            'twilio_token' => env('TWILIO_TOKEN'),
            'twilio_from' => env('TWILIO_FROM'),
            'termii_api_key' => env('TERMI_KEY'),
            'termii_secret' => env('TERMI_SECRET'),
            'termii_sender' => env('TERMI_SENDER'),
            'infobip_base_url' => env('base_url'),
            'infobip_api_key' => env('api_key'),
            'infobip_sender_from' => env('sender_from'),
            'custom_api_url' => env('CUSTOM_SMS_API_URL'),
            'custom_method' => env('CUSTOM_SMS_METHOD') ?: 'POST',
            'custom_content_type' => env('CUSTOM_SMS_CONTENT_TYPE') ?: 'json',
            'custom_sender' => env('CUSTOM_SMS_SENDER'),
            'custom_success_keyword' => env('CUSTOM_SMS_SUCCESS_KEYWORD'),
            'custom_headers' => self::decodeLegacyJson(env('CUSTOM_SMS_HEADERS')),
            'custom_payload' => self::decodeLegacyJson(env('CUSTOM_SMS_PAYLOAD')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('sms_settings');
    }

    /**
     * The .env stored headers/payload as base64-encoded JSON; the table
     * stores plain JSON.
     */
    private static function decodeLegacyJson($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || ! is_array(json_decode($decoded, true))) {
            return null;
        }

        return $decoded;
    }
}
