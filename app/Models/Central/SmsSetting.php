<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform-wide (central) SMS gateway credentials, managed by the super admin
 * in Settings > SMS. Used for SMS the platform sends to tenants (subscription
 * and trial reminders). Tenant-facing SMS keeps using the per-tenant
 * \App\Models\SmsSetting stored in each tenant database.
 */
class SmsSetting extends Model
{
    protected $connection = 'central';

    protected $table = 'sms_settings';

    protected $fillable = [
        'sms_gateway',
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

    public const GATEWAYS = [
        ''        => 'Disabled',
        'twilio'  => 'Twilio',
        'infobip' => 'Infobip',
        'termii'  => 'Termii',
        'custom'  => 'Custom (HTTP API)',
    ];

    /** Secret columns stored encrypted at rest. */
    public const SECRET_FIELDS = ['twilio_token', 'termii_api_key', 'termii_secret', 'infobip_api_key'];

    public const SECRET_MASK = '••••••••';

    public function setAttribute($key, $value)
    {
        if (in_array($key, self::SECRET_FIELDS, true)) {
            if ($value === null || $value === '' || $value === self::SECRET_MASK) {
                return $this;
            }
            $value = Crypt::encryptString($value);
        }

        return parent::setAttribute($key, $value);
    }

    public function getDecryptedSecret(string $field): ?string
    {
        if (! in_array($field, self::SECRET_FIELDS, true) || empty($this->attributes[$field])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes[$field]);
        } catch (\Throwable $e) {
            return $this->attributes[$field];
        }
    }

    /**
     * Get the single settings row (create with defaults if missing). The
     * gateway choice is seeded from the legacy GeneralSetting field so
     * existing installs keep their selection.
     */
    public static function instance(): self
    {
        $setting = static::first();

        if (! $setting) {
            $setting = static::create([
                'sms_gateway'         => GeneralSetting::instance()->sms_gateway,
                'custom_method'       => 'POST',
                'custom_content_type' => 'json',
            ]);
        }

        return $setting;
    }

    /**
     * Credentials in the shape SmsOtpSender::sendVia() expects, read solely
     * from this table (secrets decrypted).
     */
    public function credentials(): array
    {
        return [
            'twilio_sid'             => $this->twilio_sid,
            'twilio_token'           => $this->getDecryptedSecret('twilio_token'),
            'twilio_from'            => $this->twilio_from,
            'termii_api_key'         => $this->getDecryptedSecret('termii_api_key'),
            'termii_secret'          => $this->getDecryptedSecret('termii_secret'),
            'termii_sender'          => $this->termii_sender,
            'infobip_base_url'       => $this->infobip_base_url,
            'infobip_api_key'        => $this->getDecryptedSecret('infobip_api_key'),
            'infobip_sender_from'    => $this->infobip_sender_from,
            'custom_api_url'         => $this->custom_api_url,
            'custom_method'          => $this->custom_method ?: 'POST',
            'custom_content_type'    => $this->custom_content_type ?: 'json',
            'custom_sender'          => $this->custom_sender,
            'custom_success_keyword' => $this->custom_success_keyword,
            'custom_headers'         => $this->custom_headers ?: [],
            'custom_payload'         => $this->custom_payload ?: [],
        ];
    }
}
