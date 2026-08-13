<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $connection = 'central';

    protected $table = 'email_templates';

    public const TRIGGER_SUBSCRIPTION_EXPIRED  = 'subscription_expired';
    public const TRIGGER_EXPIRING_SOON         = 'expiring_soon';
    public const TRIGGER_TRIAL_ENDING          = 'trial_ending';
    public const TRIGGER_PAYMENT_SUCCESS       = 'payment_success';
    public const TRIGGER_PAYMENT_FAILED        = 'payment_failed';
    public const TRIGGER_PLAN_ENDED            = 'plan_ended';
    public const TRIGGER_TENANT_PENDING        = 'tenant_pending';
    public const TRIGGER_TENANT_UNDER_REVIEW   = 'tenant_under_review';
    public const TRIGGER_TENANT_APPROVED       = 'tenant_approved';
    public const TRIGGER_TENANT_REJECTED       = 'tenant_rejected';
    public const TRIGGER_SUPPORT_TICKET_CREATED = 'support_ticket_created';
    public const TRIGGER_SUPPORT_TICKET_REPLY   = 'support_ticket_reply';
    public const TRIGGER_SUPPORT_TICKET_STATUS  = 'support_ticket_status';

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
        '{{user_name}}'        => 'Tenant company / user name',
        '{{user_email}}'       => 'Tenant admin email address',
        '{{expiry_date}}'      => 'Subscription expiry date',
        '{{trial_end_date}}'   => 'Trial end date',
        '{{amount}}'           => 'Payment amount',
        '{{plan_name}}'        => 'Subscription plan name',
        '{{app_name}}'         => 'Application name',
        '{{app_url}}'          => 'Application URL',
        '{{resubscribe_url}}'  => 'Link to resubscribe / choose a new plan',
        '{{subdomain}}'        => 'Tenant subdomain',
        '{{tenant_url}}'       => 'Tenant workspace URL',
        '{{login_url}}'        => 'Tenant login URL',
        '{{registered_at}}'    => 'Registration date',
        '{{rejection_reason}}' => 'Rejection reason (admin provided)',
        '{{ticket_number}}'    => 'Support ticket reference number',
        '{{ticket_subject}}'   => 'Support ticket subject',
        '{{ticket_status}}'    => 'Support ticket status',
        '{{ticket_message}}'   => 'Latest support ticket message',
        '{{ticket_url}}'       => 'Link to view the support ticket',
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

    /**
     * Get the translation for a given locale, or null if not found.
     */
    public function getTranslation(string $locale): ?EmailTemplateTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /**
     * Replace placeholders with actual values and return rendered HTML.
     * Falls back to default (English) if no translation exists for the locale.
     */
    public function render(array $variables, ?string $locale = null): string
    {
        $html = $this->resolveBodyHtml($locale);

        foreach ($variables as $key => $value) {
            $html = str_replace($key, (string) $value, $html);
        }

        return $html;
    }

    /**
     * Render the subject line with variable substitution.
     */
    public function renderSubject(array $variables, ?string $locale = null): string
    {
        $subject = $this->resolveSubject($locale);

        foreach ($variables as $key => $value) {
            $subject = str_replace($key, (string) $value, $subject);
        }

        return $subject;
    }

    /**
     * Get the body HTML for a locale, falling back to the default template.
     */
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

    /**
     * Get the subject for a locale, falling back to the default template.
     */
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
