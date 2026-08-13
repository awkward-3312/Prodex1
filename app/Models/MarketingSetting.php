<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    protected $table = 'marketing_settings';

    protected $fillable = [
        'sms_enabled', 'sms_provider', 'sms_api_url', 'sms_api_key', 'sms_api_secret',
        'sms_sender_id', 'sms_http_method', 'sms_extra_params', 'sms_to_field', 'sms_message_field',
        'whatsapp_enabled', 'whatsapp_provider', 'whatsapp_api_url', 'whatsapp_api_key',
        'whatsapp_phone_id', 'whatsapp_http_method', 'whatsapp_extra_params',
        'whatsapp_to_field', 'whatsapp_message_field',
        'email_enabled', 'email_from_name', 'email_from_address',
        'default_sender_name', 'scheduling_enabled', 'batch_size',
    ];

    protected $casts = [
        'sms_enabled'           => 'boolean',
        'whatsapp_enabled'      => 'boolean',
        'email_enabled'         => 'boolean',
        'scheduling_enabled'    => 'boolean',
        'sms_extra_params'      => 'array',
        'whatsapp_extra_params' => 'array',
    ];

    /**
     * Return the single settings row, creating defaults if it does not exist yet.
     */
    public static function current()
    {
        return static::first() ?: static::create([]);
    }
}
