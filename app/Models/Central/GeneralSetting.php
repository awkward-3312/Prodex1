<?php

namespace App\Models\Central;

use App\Support\LandingPageTemplate;
use Illuminate\Database\Eloquent\Model;

class GeneralSetting extends Model
{
    protected $connection = 'central';

    protected $table = 'general_settings';

    protected $fillable = [
        'app_name',
        'currency_code',
        'currency_symbol',
        'company_name',
        'phone',
        'email',
        'address',
        'website',
        'logo_path',
        'favicon_path',
        'hosting_mode',
        'landing_template',
        'landing_font',
        'landing_heading_font',
        'landing_custom_font_name',
        'landing_custom_font_path',
        'bank_details',
        'show_customizer_button',
        'show_site_name',
        'dashboard_footer_text',
        'tenant_app_name',
        'tenant_company_name',
        'tenant_email',
        'tenant_phone',
        'tenant_address',
        'tenant_logo_path',
        'tenant_favicon_path',
        'tenant_currency_code',
        'tenant_currency_symbol',
        'tenant_default_language',
        'tenant_footer_text',
        'tenant_page_title_suffix',
        'tenant_developed_by',
        'reserved_subdomains',
        'subscription_reminders_enabled',
        'subscription_reminder_offsets',
        'subscription_reminder_channels',
        'trial_reminders_enabled',
        'trial_reminder_offsets',
        'subscription_banner_threshold_days',
        'sms_gateway',
        'subscription_reminder_sms',
        'trial_reminder_sms',
        'demo_data_enabled',
        'whatsapp_enabled',
        'whatsapp_provider',
        'whatsapp_default_templates',
    ];

    protected $casts = [
        'bank_details' => 'array',
        'reserved_subdomains' => 'array',
        'show_customizer_button' => 'boolean',
        'show_site_name' => 'boolean',
        'subscription_reminders_enabled' => 'boolean',
        'subscription_reminder_offsets' => 'array',
        'subscription_reminder_channels' => 'array',
        'trial_reminders_enabled' => 'boolean',
        'trial_reminder_offsets' => 'array',
        'subscription_banner_threshold_days' => 'integer',
        'demo_data_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'whatsapp_default_templates' => 'array',
    ];

    /**
     * Curated list of Google Font families offered for the landing pages.
     * Every family here supports weights 400/500/600/700 so the css2 request
     * never errors. Keyed by family name (also used as the CSS font-family).
     */
    public const LANDING_FONTS = [
        'Inter'             => 'Inter',
        'Open Sans'         => 'Open Sans',
        'Montserrat'        => 'Montserrat',
        'Poppins'           => 'Poppins',
        'Nunito'            => 'Nunito',
        'Nunito Sans'       => 'Nunito Sans',
        'Work Sans'         => 'Work Sans',
        'Manrope'           => 'Manrope',
        'Sora'              => 'Sora',
        'DM Sans'           => 'DM Sans',
        'Raleway'           => 'Raleway',
        'Mulish'            => 'Mulish',
        'Plus Jakarta Sans' => 'Plus Jakarta Sans',
        'Figtree'           => 'Figtree',
        'Rubik'             => 'Rubik',
        'Karla'             => 'Karla',
        'Source Sans 3'     => 'Source Sans 3',
        'Playfair Display'  => 'Playfair Display',
        'Lora'              => 'Lora',
        'Source Serif 4'    => 'Source Serif 4',
    ];

    /** Font family options for the settings dropdown (family => label). */
    public static function landingFontOptions(): array
    {
        return self::LANDING_FONTS;
    }

    /** Allowed font family keys (used for validation). */
    public static function landingFontKeys(): array
    {
        return array_keys(self::LANDING_FONTS);
    }

    /** Whether a usable custom uploaded font is configured. */
    public function hasCustomLandingFont(): bool
    {
        return ! empty($this->landing_custom_font_name)
            && ! empty($this->landing_custom_font_path)
            && $this->customLandingFontFormat() !== null;
    }

    /** CSS @font-face `format()` value derived from the uploaded file extension. */
    public function customLandingFontFormat(): ?string
    {
        if (empty($this->landing_custom_font_path)) {
            return null;
        }

        return match (strtolower(pathinfo($this->landing_custom_font_path, PATHINFO_EXTENSION))) {
            'woff2' => 'woff2',
            'woff'  => 'woff',
            'ttf'   => 'truetype',
            'otf'   => 'opentype',
            default => null,
        };
    }

    /** WhatsApp providers the platform can route through (Meta only for now). */
    public const WHATSAPP_PROVIDERS = ['meta_cloud'];

    /** Default days-before-expiry the reminder command fires on. */
    public const DEFAULT_REMINDER_OFFSETS = [7, 3, 1];

    /** Default days-before-trial-end the reminder command fires on. */
    public const DEFAULT_TRIAL_REMINDER_OFFSETS = [3, 1];

    /** Channels reminders can be delivered through. */
    public const REMINDER_CHANNELS = ['email', 'sms', 'banner'];

    public const DEFAULT_REMINDER_CHANNELS = ['email'];

    /** SMS gateway titles the platform can send reminders through. */
    public const SMS_GATEWAYS = ['twilio', 'infobip', 'termii', 'custom'];

    /** Default SMS bodies. Placeholders: {company} {plan} {date} {days} {app} */
    public const DEFAULT_REMINDER_SMS = '{company}, your {plan} subscription on {app} expires on {date} ({days} days). Renew to avoid interruption.';

    public const DEFAULT_TRIAL_SMS = '{company}, your {app} free trial ends on {date} ({days} days). Subscribe to keep your workspace active.';

    /**
     * Subdomains that are always blocked regardless of admin configuration.
     * Covers common platform / infrastructure hostnames so they can never
     * be claimed by a tenant.
     */
    public const SYSTEM_RESERVED_SUBDOMAINS = [
        'www', 'admin', 'api', 'app', 'mail', 'webmail', 'smtp', 'imap', 'pop', 'pop3',
        'ftp', 'sftp', 'ssh', 'ns', 'ns1', 'ns2', 'dns', 'mx', 'server', 'cpanel',
        'whm', 'plesk', 'webdisk', 'autodiscover', 'autoconfig', 'panel', 'support',
        'help', 'status', 'staging', 'dev', 'test', 'demo', 'beta', 'docs', 'blog',
        'cdn', 'static', 'assets', 'media', 'files', 'storage', 'backup', 'auth',
        'login', 'register', 'signup', 'billing', 'checkout', 'pay', 'payment',
        'webhook', 'webhooks', 'tenant', 'tenants', 'central', 'root', 'system',
    ];

    public function isSharedHosting(): bool
    {
        return $this->hosting_mode === 'shared';
    }

    public function isVps(): bool
    {
        return $this->hosting_mode !== 'shared';
    }

    /**
     * Whether expiry reminders should be sent at all. Defaults to true so
     * installs predating this feature keep the original behaviour.
     */
    public function remindersEnabled(): bool
    {
        return $this->subscription_reminders_enabled ?? true;
    }

    public function trialRemindersEnabled(): bool
    {
        return $this->trial_reminders_enabled ?? true;
    }

    /**
     * Sorted, deduplicated, positive days-before-expiry offsets. Falls back to
     * the configured warning window / built-in defaults when none are set.
     *
     * @return int[]
     */
    public function reminderOffsets(): array
    {
        return $this->normalizeOffsets(
            $this->subscription_reminder_offsets,
            self::DEFAULT_REMINDER_OFFSETS
        );
    }

    /**
     * @return int[]
     */
    public function trialReminderOffsets(): array
    {
        return $this->normalizeOffsets(
            $this->trial_reminder_offsets,
            self::DEFAULT_TRIAL_REMINDER_OFFSETS
        );
    }

    /**
     * Enabled delivery channels (subset of REMINDER_CHANNELS).
     *
     * @return string[]
     */
    public function reminderChannels(): array
    {
        $channels = $this->subscription_reminder_channels;

        if (! is_array($channels) || empty($channels)) {
            return self::DEFAULT_REMINDER_CHANNELS;
        }

        $channels = array_values(array_intersect(self::REMINDER_CHANNELS, $channels));

        return empty($channels) ? self::DEFAULT_REMINDER_CHANNELS : $channels;
    }

    public function reminderChannelEnabled(string $channel): bool
    {
        return in_array($channel, $this->reminderChannels(), true);
    }

    public function bannerThresholdDays(): int
    {
        $value = (int) ($this->subscription_banner_threshold_days ?? 0);

        return $value > 0 ? $value : 7;
    }

    /**
     * SMS body template for expiry reminders (falls back to the default).
     */
    public function reminderSmsTemplate(): string
    {
        $template = trim((string) ($this->subscription_reminder_sms ?? ''));

        return $template !== '' ? $template : self::DEFAULT_REMINDER_SMS;
    }

    /**
     * SMS body template for trial-ending reminders (falls back to the default).
     */
    public function trialSmsTemplate(): string
    {
        $template = trim((string) ($this->trial_reminder_sms ?? ''));

        return $template !== '' ? $template : self::DEFAULT_TRIAL_SMS;
    }

    /**
     * @param  mixed  $offsets
     * @param  int[]  $default
     * @return int[]
     */
    protected function normalizeOffsets($offsets, array $default): array
    {
        if (! is_array($offsets)) {
            return $default;
        }

        $clean = [];
        foreach ($offsets as $offset) {
            $offset = (int) $offset;
            if ($offset > 0) {
                $clean[$offset] = true;
            }
        }

        if (empty($clean)) {
            return $default;
        }

        $clean = array_keys($clean);
        rsort($clean);

        return $clean;
    }

    /**
     * Get the single settings row (create with defaults if missing).
     */
    public static function instance(): self
    {
        $setting = static::first();

        if (! $setting) {
            $setting = static::create([
                'app_name'             => config('app.name', 'Stocky'),
                'landing_template'     => LandingPageTemplate::DEFAULT,
                'dashboard_footer_text' => '© 2026 Stocky Inc. — All rights reserved.',
            ]);
        }

        return $setting;
    }

    /**
     * Get the configured currency code (cached for the request).
     */
    public static function currencyCode(): string
    {
        return static::instance()->currency_code ?? 'USD';
    }

    public static function currencySymbol(): string
    {
        return static::instance()->currency_symbol ?? '$';
    }

    /**
     * Whether newly provisioned tenants should be seeded with demo data
     * (sample catalog, customers, suppliers and transactions).
     */
    public static function demoDataEnabled(): bool
    {
        return (bool) (static::instance()->demo_data_enabled ?? false);
    }

    /**
     * Whether the WhatsApp integration is globally enabled by the super admin.
     */
    public static function whatsappEnabled(): bool
    {
        return (bool) (static::instance()->whatsapp_enabled ?? false);
    }

    /**
     * Landing layout key stored in general_settings (always a valid template key).
     */
    public static function resolvedLandingTemplateKey(): string
    {
        return LandingPageTemplate::canonicalKey(static::instance()->landing_template);
    }

    public function getBankDetails(): array
    {
        return $this->bank_details ?? [];
    }

    public function hasBankDetails(): bool
    {
        $details = $this->bank_details;
        return ! empty($details['bank_name']) || ! empty($details['account_number']) || ! empty($details['iban']);
    }

    public function getLogoUrl(): ?string
    {
        if ($this->logo_path) {
            return asset($this->logo_path);
        }

        return null;
    }

    public function getFaviconUrl(): ?string
    {
        if ($this->favicon_path) {
            return asset($this->favicon_path);
        }

        return null;
    }

    public function getTenantLogoUrl(): ?string
    {
        return $this->tenant_logo_path ? asset($this->tenant_logo_path) : null;
    }

    public function getTenantFaviconUrl(): ?string
    {
        return $this->tenant_favicon_path ? asset($this->tenant_favicon_path) : null;
    }

    /**
     * Default values used to seed new tenants. Each key has a sensible
     * fallback so existing installs keep working when no super-admin
     * customization is set.
     */
    public function getTenantDefaults(): array
    {
        return [
            'app_name'          => $this->tenant_app_name ?: ($this->app_name ?: 'Stocky | Ultimate Inventory With POS'),
            'company_name'      => $this->tenant_company_name ?: ($this->company_name ?: 'Stocky'),
            'email'             => $this->tenant_email ?: ($this->email ?: 'admin@example.com'),
            'phone'             => $this->tenant_phone ?: ($this->phone ?: ''),
            'address'           => $this->tenant_address ?: ($this->address ?: ''),
            'currency_code'     => strtoupper($this->tenant_currency_code ?: ($this->currency_code ?: 'USD')),
            'currency_symbol'   => $this->tenant_currency_symbol ?: ($this->currency_symbol ?: '$'),
            'default_language'  => $this->tenant_default_language ?: null,
            'footer_text'       => $this->tenant_footer_text ?: 'Stocky - Ultimate Inventory With POS',
            'page_title_suffix' => $this->tenant_page_title_suffix ?: 'Ultimate Inventory With POS',
            'developed_by'      => $this->tenant_developed_by ?: 'Stocky',
            'logo_path'         => $this->tenant_logo_path,
            'favicon_path'      => $this->tenant_favicon_path,
        ];
    }

    /**
     * Subdomains tenants are not allowed to register, combining the
     * built-in system list with the super admin's custom entries.
     *
     * @return string[]
     */
    public function getReservedSubdomains(): array
    {
        $custom = $this->reserved_subdomains ?? [];

        $merged = array_merge(self::SYSTEM_RESERVED_SUBDOMAINS, $custom);

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $merged
        ))));
    }

    public function isSubdomainReserved(string $subdomain): bool
    {
        return in_array(strtolower(trim($subdomain)), $this->getReservedSubdomains(), true);
    }
}
