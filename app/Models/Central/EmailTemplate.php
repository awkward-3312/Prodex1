<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $connection = 'central';
    protected $table = 'email_templates';

    public const TRIGGER_SUBSCRIPTION_EXPIRED = 'subscription_expired';
    public const TRIGGER_EXPIRING_SOON = 'expiring_soon';
    public const TRIGGER_TRIAL_ENDING = 'trial_ending';
    public const TRIGGER_PAYMENT_SUCCESS = 'payment_success';
    public const TRIGGER_PAYMENT_FAILED = 'payment_failed';
    public const TRIGGER_PLAN_ENDED = 'plan_ended';
    public const TRIGGER_TENANT_PENDING = 'tenant_pending';
    public const TRIGGER_TENANT_UNDER_REVIEW = 'tenant_under_review';
    public const TRIGGER_TENANT_APPROVED = 'tenant_approved';
    public const TRIGGER_TENANT_REJECTED = 'tenant_rejected';
    public const TRIGGER_SUPPORT_TICKET_CREATED = 'support_ticket_created';
    public const TRIGGER_SUPPORT_TICKET_REPLY = 'support_ticket_reply';
    public const TRIGGER_SUPPORT_TICKET_STATUS = 'support_ticket_status';

    public const TRIGGERS = [
        self::TRIGGER_SUBSCRIPTION_EXPIRED,
        self::TRIGGER_EXPIRING_SOON,
        self::TRIGGER_TRIAL_ENDING,
        self::TRIGGER_PAYMENT_SUCCESS,
        self::TRIGGER_PAYMENT_FAILED,
        self::TRIGGER_PLAN_ENDED,
        self::TRIGGER_TENANT_PENDING,
        self::TRIGGER_TENANT_UNDER_REVIEW,
        self::TRIGGER_TENANT_APPROVED,
        self::TRIGGER_TENANT_REJECTED,
        self::TRIGGER_SUPPORT_TICKET_CREATED,
        self::TRIGGER_SUPPORT_TICKET_REPLY,
        self::TRIGGER_SUPPORT_TICKET_STATUS,
    ];

    public const AVAILABLE_VARIABLES = [
        '{{user_name}}' => 'Nombre de la empresa o usuario del tenant',
        '{{user_email}}' => 'Correo electrónico del administrador del tenant',
        '{{expiry_date}}' => 'Fecha de vencimiento de la suscripción',
        '{{trial_end_date}}' => 'Fecha de finalización del período de prueba',
        '{{amount}}' => 'Monto del pago con moneda',
        '{{plan_name}}' => 'Nombre del plan de suscripción',
        '{{app_name}}' => 'Nombre de la aplicación',
        '{{app_url}}' => 'URL principal de PRODEX',
        '{{resubscribe_url}}' => 'Enlace para volver a suscribirse o elegir un plan',
        '{{subdomain}}' => 'Subdominio del tenant',
        '{{tenant_url}}' => 'URL del espacio de trabajo del tenant',
        '{{login_url}}' => 'URL de inicio de sesión del tenant',
        '{{registered_at}}' => 'Fecha y hora del registro',
        '{{rejection_reason}}' => 'Motivo de rechazo indicado por el administrador',
        '{{ticket_number}}' => 'Número de referencia del ticket de soporte',
        '{{ticket_subject}}' => 'Asunto del ticket de soporte',
        '{{ticket_status}}' => 'Estado actual del ticket de soporte',
        '{{ticket_message}}' => 'Último mensaje del ticket de soporte',
        '{{ticket_url}}' => 'Enlace para ver el ticket de soporte',
    ];

    protected $fillable = [
        'trigger_key',
        'name',
        'subject',
        'body_html',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function translations()
    {
        return $this->hasMany(EmailTemplateTranslation::class, 'email_template_id');
    }

    public function getTranslation(string $locale): ?EmailTemplateTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    public function render(array $variables, ?string $locale = null): string
    {
        $html = $this->resolveBodyHtml($locale);

        foreach ($variables as $key => $value) {
            $html = str_replace($key, $this->normalizeVariableValue($key, $value), $html);
        }

        return $html;
    }

    public function renderSubject(array $variables, ?string $locale = null): string
    {
        $subject = $this->resolveSubject($locale);

        foreach ($variables as $key => $value) {
            $subject = str_replace($key, $this->normalizeVariableValue($key, $value), $subject);
        }

        return $subject;
    }

    protected function normalizeVariableValue(string $key, mixed $value): string
    {
        $value = (string) $value;

        if ($key !== '{{ticket_status}}') {
            return $value;
        }

        return match (strtolower(trim($value))) {
            'open' => 'Abierto',
            'pending' => 'Pendiente',
            'in_progress', 'in progress' => 'En proceso',
            'resolved' => 'Resuelto',
            'closed' => 'Cerrado',
            default => $value,
        };
    }

    protected function resolveBodyHtml(?string $locale): string
    {
        if ($locale) {
            $translation = $this->getTranslation($locale);
            if ($translation && ! empty($translation->body_html)) {
                return $translation->body_html;
            }
        }

        return $this->body_html;
    }

    protected function resolveSubject(?string $locale): string
    {
        if ($locale) {
            $translation = $this->getTranslation($locale);
            if ($translation && ! empty($translation->subject)) {
                return $translation->subject;
            }
        }

        return $this->subject;
    }
}
