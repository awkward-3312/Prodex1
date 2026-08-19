<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantillas SMS de notificaciones de la plataforma, administradas por el
 * SuperAdmin. Los cuerpos son texto plano con variables reemplazables.
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
        '{company}' => 'Nombre de la empresa del tenant',
        '{plan}'    => 'Nombre del plan de suscripción',
        '{date}'    => 'Fecha de vencimiento o fin de prueba',
        '{days}'    => 'Días restantes',
        '{app}'     => 'Nombre de la aplicación',
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

    public function getTranslation(string $locale): ?SmsTemplateTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    public function render(array $variables, ?string $locale = null): string
    {
        return strtr($this->resolveBody($locale), $variables);
    }

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
