<?php

namespace App\Mail;

use App\Models\Central\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantPendingApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $companyName;
    public string $subdomain;
    public string $adminEmail;
    public string $registeredAt;

    public function __construct(string $companyName, string $subdomain, string $adminEmail, string $registeredAt, ?string $locale = null)
    {
        $this->companyName = $companyName;
        $this->subdomain = $subdomain;
        $this->adminEmail = $adminEmail;
        $this->registeredAt = $registeredAt;
        $this->locale = $locale;
    }

    public function build()
    {
        $template = EmailTemplate::with('translations')
            ->where('trigger_key', EmailTemplate::TRIGGER_TENANT_PENDING)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return $this->subject('New Tenant Pending Approval')
                ->html('<p>A new tenant "' . e($this->companyName) . '" (' . e($this->subdomain) . ') is pending approval.</p>');
        }

        $variables = [
            '{{user_name}}'     => $this->companyName,
            '{{user_email}}'    => $this->adminEmail,
            '{{subdomain}}'     => $this->subdomain,
            '{{registered_at}}' => $this->registeredAt,
            '{{app_name}}'      => config('app.name', 'Stocky'),
            '{{app_url}}'       => config('app.url', 'http://localhost'),
        ];

        return $this->subject($template->renderSubject($variables, $this->locale))
            ->html($template->render($variables, $this->locale));
    }
}
