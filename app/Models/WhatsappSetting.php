<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSetting extends Model
{
    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'provider',
        'phone_number_id',
        'business_account_id',
        'access_token',
        'app_secret',
        'webhook_verify_token',
        'default_country_code',
        'is_connected',
        'connected_at',
        'automations',
    ];

    protected $casts = [
        'is_connected'  => 'boolean',
        'connected_at'  => 'datetime',
        'automations'   => 'array',
        // Credentials are encrypted at rest using the app key.
        'access_token'  => 'encrypted',
        'app_secret'    => 'encrypted',
    ];

    /** Sensitive attributes never serialized to the SPA. */
    protected $hidden = [
        'access_token',
        'app_secret',
    ];

    /**
     * Automation trigger keys the tenant can toggle. Only triggers that are
     * actually wired to a business event are listed here (MVP: Sales). New
     * triggers (orders, stock, HRM, customers) will be appended as they are
     * hooked into their respective flows.
     */
    public const AUTOMATIONS = [
        'invoice_created',   // a sale / invoice is created
        'payment_received',  // a payment is recorded against a sale
    ];

    /**
     * Get the single settings row, creating an empty one if missing.
     */
    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'provider'     => 'meta_cloud',
            'is_connected' => false,
            'automations'  => [],
        ]);
    }

    /**
     * Whether an automation trigger is enabled for this tenant.
     */
    public function automationEnabled(string $key): bool
    {
        $automations = $this->automations ?? [];

        return (bool) ($automations[$key] ?? false);
    }

    /**
     * Whether the tenant has the minimum credentials to send.
     */
    public function hasCredentials(): bool
    {
        return ! empty($this->phone_number_id) && ! empty($this->access_token);
    }
}
