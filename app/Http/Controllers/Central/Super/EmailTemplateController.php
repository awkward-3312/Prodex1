<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Models\Central\EmailTemplate;
use App\Models\Central\EmailTemplateTranslation;
use App\Models\Central\GeneralSetting;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function index()
    {
        $templates = EmailTemplate::with('translations')
            ->orderByRaw("FIELD(trigger_key, 'subscription_expired', 'expiring_soon', 'trial_ending', 'payment_success', 'payment_failed', 'plan_ended', 'tenant_pending', 'tenant_under_review', 'tenant_approved', 'tenant_rejected', 'support_ticket_created', 'support_ticket_reply', 'support_ticket_status')")
            ->get();

        $triggerLabels = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => 'Suscripción vencida',
            EmailTemplate::TRIGGER_EXPIRING_SOON => 'Próxima a vencer',
            EmailTemplate::TRIGGER_TRIAL_ENDING => 'Prueba próxima a finalizar',
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS => 'Pago recibido',
            EmailTemplate::TRIGGER_PAYMENT_FAILED => 'Pago no completado',
            EmailTemplate::TRIGGER_PLAN_ENDED => 'Plan finalizado',
            EmailTemplate::TRIGGER_TENANT_PENDING => 'Registro pendiente',
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW => 'Cuenta en revisión',
            EmailTemplate::TRIGGER_TENANT_APPROVED => 'Cuenta aprobada',
            EmailTemplate::TRIGGER_TENANT_REJECTED => 'Registro no aprobado',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_CREATED => 'Ticket recibido',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_REPLY => 'Respuesta de soporte',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_STATUS => 'Estado del ticket',
        ];

        $triggerIcons = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => 'bi-calendar-x',
            EmailTemplate::TRIGGER_EXPIRING_SOON => 'bi-clock-history',
            EmailTemplate::TRIGGER_TRIAL_ENDING => 'bi-hourglass',
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS => 'bi-check-circle',
            EmailTemplate::TRIGGER_PAYMENT_FAILED => 'bi-x-circle',
            EmailTemplate::TRIGGER_PLAN_ENDED => 'bi-calendar-minus',
            EmailTemplate::TRIGGER_TENANT_PENDING => 'bi-hourglass-split',
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW => 'bi-search',
            EmailTemplate::TRIGGER_TENANT_APPROVED => 'bi-person-check',
            EmailTemplate::TRIGGER_TENANT_REJECTED => 'bi-person-x',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_CREATED => 'bi-life-preserver',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_REPLY => 'bi-chat-left-text',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_STATUS => 'bi-arrow-repeat',
        ];

        $triggerColors = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => '#ef4444',
            EmailTemplate::TRIGGER_EXPIRING_SOON => '#f59e0b',
            EmailTemplate::TRIGGER_TRIAL_ENDING => '#6366f1',
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS => '#10b981',
            EmailTemplate::TRIGGER_PAYMENT_FAILED => '#ef4444',
            EmailTemplate::TRIGGER_PLAN_ENDED => '#6366f1',
            EmailTemplate::TRIGGER_TENANT_PENDING => '#6366f1',
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW => '#f59e0b',
            EmailTemplate::TRIGGER_TENANT_APPROVED => '#10b981',
            EmailTemplate::TRIGGER_TENANT_REJECTED => '#ef4444',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_CREATED => '#6366f1',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_REPLY => '#6366f1',
            EmailTemplate::TRIGGER_SUPPORT_TICKET_STATUS => '#6366f1',
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
        $translation = $currentLocale ? $template->getTranslation($currentLocale) : null;

        return view('central.super.email-templates.edit', compact(
            'template', 'variables', 'languages', 'currentLocale', 'translation'
        ));
    }

    public function update(Request $request, EmailTemplate $template)
    {
        $locale = $request->input('locale');

        if ($locale) {
            $validated = $request->validate([
                'subject' => ['required', 'string', 'max:255'],
                'body_html' => ['required', 'string'],
            ]);

            EmailTemplateTranslation::updateOrCreate(
                ['email_template_id' => $template->id, 'locale' => $locale],
                ['subject' => $validated['subject'], 'body_html' => $validated['body_html']]
            );

            return redirect()
                ->route('super.email-templates.edit', ['template' => $template->id, 'locale' => $locale])
                ->with('success', 'Traducción guardada correctamente.');
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'is_active' => ['nullable'],
        ]);

        $template->update([
            'subject' => $validated['subject'],
            'body_html' => $validated['body_html'],
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()
            ->route('super.email-templates.edit', $template)
            ->with('success', "Plantilla \"{$template->name}\" actualizada correctamente.");
    }

    public function destroyTranslation(EmailTemplate $template, string $locale)
    {
        EmailTemplateTranslation::where('email_template_id', $template->id)
            ->where('locale', $locale)
            ->delete();

        return redirect()
            ->route('super.email-templates.edit', $template)
            ->with('success', 'Traducción eliminada correctamente.');
    }

    public function preview(EmailTemplate $template, Request $request)
    {
        $template->load('translations');
        $locale = $request->query('locale');
        $html = $template->render($this->getSampleVariables(), $locale);

        $faviconUrl = GeneralSetting::instance()->getFaviconUrl()
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
        $request->validate(['test_email' => ['required', 'email']]);

        $template->load('translations');
        $locale = $request->input('locale');
        $sampleVars = $this->getSampleVariables();
        $sampleVars['{{user_email}}'] = $request->test_email;
        $localeSuffix = $locale ? " [{$locale}]" : '';

        try {
            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($template, $sampleVars, $request, $locale, $localeSuffix) {
                $message->to($request->test_email)
                    ->subject('[PRUEBA]' . $localeSuffix . ' ' . $template->renderSubject($sampleVars, $locale))
                    ->html($template->render($sampleVars, $locale));
            });

            return back()->with('success', 'Correo de prueba enviado correctamente a ' . $request->test_email . '.');
        } catch (\Throwable $e) {
            return back()->withErrors(['test_email' => 'No se pudo enviar el correo de prueba: ' . $e->getMessage()]);
        }
    }

    protected function getSampleVariables(): array
    {
        $settings = GeneralSetting::instance();
        $appUrl = rtrim((string) config('app.url', 'https://prodexhub.cloud'), '/');
        $host = preg_replace('/^www\./i', '', (string) parse_url($appUrl, PHP_URL_HOST));
        $tenantUrl = 'https://empresa-demo.' . $host;
        $amount = ($settings->currency_symbol ?: 'L.') . '2,499.00 ' . ($settings->currency_code ?: 'HNL');

        return [
            '{{user_name}}' => 'Empresa Demo',
            '{{user_email}}' => 'cliente@ejemplo.com',
            '{{expiry_date}}' => now()->addDays(30)->format('d/m/Y'),
            '{{trial_end_date}}' => now()->addDays(7)->format('d/m/Y'),
            '{{amount}}' => $amount,
            '{{plan_name}}' => 'Empresarial',
            '{{app_name}}' => $settings->app_name ?: 'PRODEX',
            '{{app_url}}' => $appUrl,
            '{{resubscribe_url}}' => $tenantUrl . '/app/billing/change-plan',
            '{{subdomain}}' => 'empresa-demo',
            '{{tenant_url}}' => $tenantUrl,
            '{{login_url}}' => $tenantUrl . '/login',
            '{{registered_at}}' => now()->format('d/m/Y H:i'),
            '{{rejection_reason}}' => 'No fue posible validar la información proporcionada.',
            '{{ticket_number}}' => 'TK-000123',
            '{{ticket_subject}}' => 'Consulta sobre facturación',
            '{{ticket_status}}' => 'Abierto',
            '{{ticket_message}}' => 'Hemos revisado tu solicitud y te compartimos la información solicitada.',
            '{{ticket_url}}' => $tenantUrl . '/app/support/tickets/123',
        ];
    }
}
