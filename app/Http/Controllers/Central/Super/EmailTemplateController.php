<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Models\Central\EmailTemplate;
use App\Models\Central\EmailTemplateTranslation;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function index()
    {
        $templates = EmailTemplate::with('translations')
            ->orderByRaw("FIELD(trigger_key, 'subscription_expired', 'expiring_soon', 'payment_success', 'payment_failed', 'plan_ended', 'tenant_pending', 'tenant_under_review', 'tenant_approved', 'tenant_rejected')")
            ->get();

        $triggerLabels = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => 'Subscription Expired',
            EmailTemplate::TRIGGER_EXPIRING_SOON        => 'Expiring Soon',
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS      => 'Payment Success',
            EmailTemplate::TRIGGER_PAYMENT_FAILED       => 'Payment Failed',
            EmailTemplate::TRIGGER_PLAN_ENDED           => 'Plan Ended',
            EmailTemplate::TRIGGER_TENANT_PENDING        => 'Tenant Pending',
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW   => 'Under Review',
            EmailTemplate::TRIGGER_TENANT_APPROVED       => 'Tenant Approved',
            EmailTemplate::TRIGGER_TENANT_REJECTED       => 'Tenant Rejected',
        ];

        $triggerIcons = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => 'bi-calendar-x',
            EmailTemplate::TRIGGER_EXPIRING_SOON        => 'bi-clock-history',
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS      => 'bi-check-circle',
            EmailTemplate::TRIGGER_PAYMENT_FAILED       => 'bi-x-circle',
            EmailTemplate::TRIGGER_PLAN_ENDED           => 'bi-calendar-minus',
            EmailTemplate::TRIGGER_TENANT_PENDING        => 'bi-hourglass-split',
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW   => 'bi-search',
            EmailTemplate::TRIGGER_TENANT_APPROVED       => 'bi-person-check',
            EmailTemplate::TRIGGER_TENANT_REJECTED       => 'bi-person-x',
        ];

        $triggerColors = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => '#ef4444',
            EmailTemplate::TRIGGER_EXPIRING_SOON        => '#f59e0b',
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS      => '#10b981',
            EmailTemplate::TRIGGER_PAYMENT_FAILED       => '#ef4444',
            EmailTemplate::TRIGGER_PLAN_ENDED           => '#6366f1',
            EmailTemplate::TRIGGER_TENANT_PENDING        => '#6366f1',
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW   => '#f59e0b',
            EmailTemplate::TRIGGER_TENANT_APPROVED       => '#10b981',
            EmailTemplate::TRIGGER_TENANT_REJECTED       => '#ef4444',
        ];

        $languages = CentralLanguage::active();

        return view('central.super.email-templates.index', compact(
            'templates', 'triggerLabels', 'triggerIcons', 'triggerColors', 'languages'
        ));
    }

    public function edit(EmailTemplate $template, Request $request)
    {
        $template->load('translations');
        $variables = EmailTemplate::AVAILABLE_VARIABLES;
        $languages = CentralLanguage::active();
        $currentLocale = $request->query('locale');

        // If a locale is requested, load that translation
        $translation = null;
        if ($currentLocale) {
            $translation = $template->getTranslation($currentLocale);
        }

        return view('central.super.email-templates.edit', compact(
            'template', 'variables', 'languages', 'currentLocale', 'translation'
        ));
    }

    public function update(Request $request, EmailTemplate $template)
    {
        $locale = $request->input('locale');

        if ($locale) {
            // Saving a translation
            $validated = $request->validate([
                'subject'   => ['required', 'string', 'max:255'],
                'body_html' => ['required', 'string'],
            ]);

            EmailTemplateTranslation::updateOrCreate(
                [
                    'email_template_id' => $template->id,
                    'locale'            => $locale,
                ],
                [
                    'subject'   => $validated['subject'],
                    'body_html' => $validated['body_html'],
                ]
            );

            return redirect()
                ->route('super.email-templates.edit', ['template' => $template->id, 'locale' => $locale])
                ->with('success', "Translation for \"{$locale}\" saved successfully.");
        }

        // Saving the default (English) template
        $validated = $request->validate([
            'subject'   => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'is_active' => ['nullable'],
        ]);

        $template->update([
            'subject'   => $validated['subject'],
            'body_html' => $validated['body_html'],
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()
            ->route('super.email-templates.edit', $template)
            ->with('success', "Template \"{$template->name}\" updated successfully.");
    }

    /**
     * Delete a specific translation.
     */
    public function destroyTranslation(EmailTemplate $template, string $locale)
    {
        EmailTemplateTranslation::where('email_template_id', $template->id)
            ->where('locale', $locale)
            ->delete();

        return redirect()
            ->route('super.email-templates.edit', $template)
            ->with('success', "Translation for \"{$locale}\" removed.");
    }

    public function preview(EmailTemplate $template, Request $request)
    {
        $template->load('translations');
        $locale = $request->query('locale');
        $sampleVars = $this->getSampleVariables();

        $html = $template->render($sampleVars, $locale);

        $faviconUrl = \App\Models\Central\GeneralSetting::instance()->getFaviconUrl()
            ?: asset('images/super/settings/favicon.ico');
        $faviconTag = '<link rel="icon" href="' . e($faviconUrl) . '" type="image/x-icon">';

        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $faviconTag . '</head>', $html);
        } elseif (stripos($html, '<body') !== false) {
            $html = preg_replace('/<body/i', '<head>' . $faviconTag . '</head><body', $html, 1);
        } else {
            $html = $faviconTag . $html;
        }

        return response($html)->header('Content-Type', 'text/html');
    }

    public function sendTest(Request $request, EmailTemplate $template)
    {
        $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        $template->load('translations');
        $locale = $request->input('locale');
        $sampleVars = $this->getSampleVariables();
        $sampleVars['{{user_email}}'] = $request->test_email;

        $localeSuffix = $locale ? " [{$locale}]" : '';

        try {
            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($template, $sampleVars, $request, $locale, $localeSuffix) {
                $message->to($request->test_email)
                    ->subject('[TEST]' . $localeSuffix . ' ' . $template->renderSubject($sampleVars, $locale))
                    ->html($template->render($sampleVars, $locale));
            });

            return back()->with('success', "Test email{$localeSuffix} sent to {$request->test_email}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['test_email' => 'Failed to send test email: ' . $e->getMessage()]);
        }
    }

    protected function getSampleVariables(): array
    {
        return [
            '{{user_name}}'        => 'John Doe',
            '{{user_email}}'       => 'john@example.com',
            '{{expiry_date}}'      => now()->addDays(7)->format('M d, Y'),
            '{{amount}}'           => '$29.99',
            '{{plan_name}}'        => 'Professional',
            '{{app_name}}'         => config('app.name', 'Stocky'),
            '{{app_url}}'          => config('app.url', 'http://localhost'),
            '{{resubscribe_url}}'  => config('app.url', 'http://localhost') . '/register',
            '{{subdomain}}'        => 'demo-company',
            '{{tenant_url}}'       => 'https://demo-company.' . preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST)),
            '{{login_url}}'        => 'https://demo-company.' . preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST)) . '/login',
            '{{registered_at}}'    => now()->format('M d, Y H:i'),
            '{{rejection_reason}}' => 'Your application did not meet the requirements at this time.',
        ];
    }
}
