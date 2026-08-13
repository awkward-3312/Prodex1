<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform SMS notification templates, managed by the super admin like the
 * email templates. Bodies are plain text with {company} {plan} {date} {days}
 * {app} placeholders (same set the subscription-reminder command substitutes).
 */
class SmsTemplate extends Model
{
    protected $connection = 'central';

    protected $table = 'sms_templates';

    public const TRIGGER_EXPIRING_SOON = 'expiring_soon';
    public const TRIGGER_TRIAL_ENDING  = 'trial_ending';

    public const TRIGGERS = [
        self::TRIGGER_EXPIRING_SOON,
        self::TRIGGER_TRIAL_ENDING,
    ];

    public const AVAILABLE_VARIABLES = [
        '{company}' => 'Tenant company name',
        '{plan}'    => 'Subscription plan name',
        '{date}'    => 'Expiry / trial end date',
        '{days}'    => 'Days remaining',
        '{app}'     => 'Application name',
    ];

    protected $fillable = [
        'trigger_key',
        'name',
        'body',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function translations()
    {
        return $this->hasMany(SmsTemplateTranslation::class, 'sms_template_id');
    }

    /**
     * Get the translation for a given locale, or null if not found.
     */
    public function getTranslation(string $locale): ?SmsTemplateTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /**
     * Replace placeholders with actual values and return the rendered message.
     * Falls back to the default body if no translation exists for the locale.
     */
    public function render(array $variables, ?string $locale = null): string
    {
        return strtr($this->resolveBody($locale), $variables);
    }

    /**
     * Get the body for a locale, falling back to the default template.
     */
    protected function resolveBody(?string $locale): string
    {
        if ($locale) {
            $translation = $this->getTranslation($locale);
            if ($translation && ! empty($translation->body)) {
                return $translation->body;
            }
        }

        return $this->body;
    }

    /**
     * Resolve the message body for a trigger and locale. Returns $fallback
     * when the template is missing or deactivated, so legacy behavior (the
     * General Settings SMS texts) is preserved.
     */
    public static function bodyFor(string $triggerKey, ?string $locale, string $fallback): string
    {
        $template = static::with('translations')
            ->where('trigger_key', $triggerKey)
            ->first();

        if (! $template || ! $template->is_active) {
            return $fallback;
        }

        if ($locale && $locale === CentralLanguage::defaultLocale()) {
            $locale = null;
        }

        return $template->resolveBody($locale);
    }
}
