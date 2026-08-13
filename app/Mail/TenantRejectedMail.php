<?php

namespace App\Mail;

use App\Models\Central\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $companyName;
    public string $reason;
    public string $appName;
    public ?string $preferredLocale;

    public function __construct(string $companyName, string $reason, string $appName, ?string $locale = null)
    {
        $this->companyName = $companyName;
        $this->reason = $reason;
        $this->appName = $appName;
        $this->preferredLocale = $locale;
    }

    public function build()
    {
        $template = EmailTemplate::with('translations')
            ->where('trigger_key', EmailTemplate::TRIGGER_TENANT_REJECTED)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return $this->subject('Registration Update')
                ->html('<p>Hi ' . e($this->companyName) . ', your registration was not approved. Reason: ' . e($this->reason) . '</p>');
        }

        $variables = [
            '{{user_name}}'        => $this->companyName,
            '{{rejection_reason}}' => $this->reason,
            '{{app_name}}'         => $this->appName,
            '{{app_url}}'          => config('app.url', 'http://localhost'),
        ];

        return $this->subject($template->renderSubject($variables, $this->preferredLocale))
            ->html($template->render($variables, $this->preferredLocale));
    }
}
