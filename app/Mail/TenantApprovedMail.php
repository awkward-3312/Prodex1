<?php

namespace App\Mail;

use App\Models\Central\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $companyName;
    public string $tenantUrl;
    public string $loginUrl;
    public string $appName;
    // NOTE: $locale is inherited (untyped) from Mailable — redeclaring it with
    // a type is a PHP fatal error. Assigning it also makes Laravel render the
    // mail in that locale.

    public function __construct(string $companyName, string $tenantUrl, string $loginUrl, string $appName, ?string $locale = null)
    {
        $this->companyName = $companyName;
        $this->tenantUrl = $tenantUrl;
        $this->loginUrl = $loginUrl;
        $this->appName = $appName;
        $this->locale = $locale;
    }

    public function build()
    {
        $template = EmailTemplate::with('translations')
            ->where('trigger_key', EmailTemplate::TRIGGER_TENANT_APPROVED)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return $this->subject('Your Account is Ready!')
                ->html('<p>Hi ' . e($this->companyName) . ', your workspace is ready at <a href="' . e($this->loginUrl) . '">' . e($this->loginUrl) . '</a>.</p>');
        }

        $variables = [
            '{{user_name}}'   => $this->companyName,
            '{{tenant_url}}'  => $this->tenantUrl,
            '{{login_url}}'   => $this->loginUrl,
            '{{app_name}}'    => $this->appName,
            '{{app_url}}'     => config('app.url', 'http://localhost'),
        ];

        return $this->subject($template->renderSubject($variables, $this->locale))
            ->html($template->render($variables, $this->locale));
    }
}
