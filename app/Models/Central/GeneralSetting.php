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

    public static function landingFontOptions(): array
    {
        return self::LANDING_FONTS;
    }

    public static function landingFontKeys(): array
    {
        return array_keys(self::LANDING_FONTS);
    }

    public function hasCustomLandingFont(): bool
    {
        return ! empty($this->landing_custom_font_name)
            && ! empty($this->landing_custom_font_path)
            && $this->customLandingFontFormat() !== null;
    }

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

    public const WHATSAPP_PROVIDERS = ['meta_cloud'];
    public const DEFAULT_REMINDER_OFFSETS = [7, 3, 1];
    public const DEFAULT_TRIAL_REMINDER_OFFSETS = [3, 1];
    public const REMINDER_CHANNELS = ['email', 'sms', 'banner'];
    public const DEFAULT_REMINDER_CHANNELS = ['email'];
    public const SMS_GATEWAYS = ['twilio', 'infobip', 'termii', 'custom'];

    /** Default SMS bodies. Placeholders: {company} {plan} {date} {days} {app} */
    public const DEFAULT_REMINDER_SMS = '{company}, tu suscripción al plan {plan} en {app} vence el {date} ({days} días). Renueva para evitar interrupciones.';
    public const DEFAULT_TRIAL_SMS = '{company}, tu período de prueba de {app} termina el {date} ({days} días). Suscríbete para mantener activo tu espacio de trabajo.';

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

    public function remindersEnabled(): bool
    {
        return $this->subscription_reminders_enabled ?? true;
    }

    public function trialRemindersEnabled(): bool
    {
        return $this->trial_reminders_enabled ?? true;
    }

    public function reminderOffsets(): array
    {
        return $this->normalizeOffsets(
            $this->subscription_reminder_offsets,
            self::DEFAULT_REMINDER_OFFSETS
        );
    }

    public function trialReminderOffsets(): array
    {
        return $this->normalizeOffsets(
            $this->trial_reminder_offsets,
            self::DEFAULT_TRIAL_REMINDER_OFFSETS
        );
    }

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

    public function reminderSmsTemplate(): string
    {
        $template = trim((string) ($this->subscription_reminder_sms ?? ''));

        return $template !== '' ? $template : self::DEFAULT_REMINDER_SMS;
    }

    public function trialSmsTemplate(): string
    {
        $template = trim((string) ($this->trial_reminder_sms ?? ''));

        return $template !== '' ? $template : self::DEFAULT_TRIAL_SMS;
    }

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

    public static function instance(): self
    {
        $setting = static::first();

        if (! $setting) {
            $setting = static::create([
                'app_name'              => config('app.name', 'PRODEX'),
                'company_name'          => 'PRODEX',
                'currency_code'         => 'HNL',
                'currency_symbol'       => 'L',
                'landing_template'      => LandingPageTemplate::DEFAULT,
                'dashboard_footer_text' => '© '.date('Y').' PRODEX — Todos los derechos reservados.',
                'tenant_app_name'       => 'PRODEX',
                'tenant_company_name'   => 'PRODEX',
                'tenant_currency_code'  => 'HNL',
                'tenant_currency_symbol'=> 'L',
                'tenant_default_language' => 'es',
                'tenant_footer_text'    => 'PRODEX',
                'tenant_page_title_suffix' => 'Gestión empresarial',
                'tenant_developed_by'   => 'PRODEX',
            ]);
        }

        return $setting;
    }

    public static function currencyCode(): string
    {
        return static::instance()->currency_code ?? 'HNL';
    }

    public static function currencySymbol(): string
    {
        return static::instance()->currency_symbol ?? 'L';
    }

    public static function demoDataEnabled(): bool
    {
        return (bool) (static::instance()->demo_data_enabled ?? false);
    }

    public static function whatsappEnabled(): bool
    {
        return (bool) (static::instance()->whatsapp_enabled ?? false);
    }

    public static function resolvedLandingTemplateKey(): string
    {
        return LandingPageTemplate::canonicalKey(static::instance()->landing_template);
    }

    public function getBankDetails(): array
    {
        return $this->bank_details ?? [];
    }

    public function setBankDetailsAttribute($value): void
    {
        $incoming = is_array($value) ? $value : [];
        $current = [];

        if (! empty($this->attributes['bank_details'])) {
            $decoded = json_decode((string) $this->attributes['bank_details'], true);
            $current = is_array($decoded) ? $decoded : [];
        }

        if (! array_key_exists('accounts', $incoming)
            && isset($current['accounts'])
            && is_array($current['accounts'])) {
            $incoming['accounts'] = $current['accounts'];
        }

        $this->attributes['bank_details'] = json_encode(
            $incoming,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
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

    public function getTenantDefaults(): array
    {
        return [
            'app_name'          => $this->tenant_app_name ?: ($this->app_name ?: 'PRODEX'),
            'company_name'      => $this->tenant_company_name ?: ($this->company_name ?: 'PRODEX'),
            'email'             => $this->tenant_email ?: ($this->email ?: 'admin@prodexhub.cloud'),
            'phone'             => $this->tenant_phone ?: ($this->phone ?: ''),
            'address'           => $this->tenant_address ?: ($this->address ?: ''),
            'currency_code'     => strtoupper($this->tenant_currency_code ?: ($this->currency_code ?: 'HNL')),
            'currency_symbol'   => $this->tenant_currency_symbol ?: ($this->currency_symbol ?: 'L'),
            'default_language'  => $this->tenant_default_language ?: 'es',
            'footer_text'       => $this->tenant_footer_text ?: 'PRODEX',
            'page_title_suffix' => $this->tenant_page_title_suffix ?: 'Gestión empresarial',
            'developed_by'      => $this->tenant_developed_by ?: 'PRODEX',
            'logo_path'         => $this->tenant_logo_path,
            'favicon_path'      => $this->tenant_favicon_path,
        ];
    }

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
