<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant SMS gateway credentials. Lives in the tenant database so every
 * tenant has isolated settings that take effect immediately (no .env writes,
 * no config:clear).
 */
class SmsSetting extends Model
{
    protected $table = 'sms_settings';

    protected $fillable = [
        'twilio_sid', 'twilio_token', 'twilio_from',
        'termii_api_key', 'termii_secret', 'termii_sender',
        'infobip_base_url', 'infobip_api_key', 'infobip_sender_from',
        'custom_api_url', 'custom_method', 'custom_content_type',
        'custom_sender', 'custom_success_keyword', 'custom_headers', 'custom_payload',
    ];

    protected $casts = [
        'custom_headers' => 'array',
        'custom_payload' => 'array',
    ];

    /**
     * Return the single settings row, creating defaults if it does not exist yet.
     */
    public static function current()
    {
        return static::first() ?: static::create([]);
    }
}
