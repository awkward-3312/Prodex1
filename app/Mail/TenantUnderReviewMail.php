<?php

namespace App\Mail;

use App\Models\Central\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantUnderReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $companyName;
    public string $appName;
    public ?string $preferredLocale;

    public function __construct(string $companyName, string $appName, ?string $locale = null)
    {
        $this->companyName = $companyName;
        $this->appName = $appName;
        $this->preferredLocale = $locale;
    }

    public function build()
    {
        $template = EmailTemplate::with('translations')
            ->where('trigger_key', EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return $this->subject('Your Account is Under Review')
                ->html('<p>Hi ' . e($this->companyName) . ', your account is under review. We will notify you once approved.</p>');
        }

        $variables = [
            '{{user_name}}' => $this->companyName,
            '{{app_name}}'  => $this->appName,
            '{{app_url}}'   => config('app.url', 'http://localhost'),
        ];

        return $this->subject($template->renderSubject($variables, $this->preferredLocale))
            ->html($template->render($variables, $this->preferredLocale));
    }
}
