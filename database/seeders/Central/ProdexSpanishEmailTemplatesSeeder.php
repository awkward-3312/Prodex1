<?php

namespace Database\Seeders\Central;

use App\Models\Central\EmailTemplate;
use Illuminate\Database\Seeder;

class ProdexSpanishEmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED => [
                'name' => 'Suscripción vencida',
                'subject' => 'Tu suscripción {{plan_name}} ha vencido',
                'title' => 'Tu suscripción ha vencido',
                'body' => 'Hola <strong>{{user_name}}</strong>. Tu suscripción al plan <strong>{{plan_name}}</strong> venció el <strong>{{expiry_date}}</strong>. Algunas funciones pueden quedar restringidas hasta que renueves tu plan.',
                'cta' => 'Renovar suscripción',
                'url' => '{{app_url}}/app/billing/change-plan',
                'color' => '#ef4444',
            ],
            EmailTemplate::TRIGGER_EXPIRING_SOON => [
                'name' => 'Suscripción próxima a vencer',
                'subject' => 'Tu suscripción {{plan_name}} vence el {{expiry_date}}',
                'title' => 'Tu suscripción está próxima a vencer',
                'body' => 'Hola <strong>{{user_name}}</strong>. Tu suscripción al plan <strong>{{plan_name}}</strong> vence el <strong>{{expiry_date}}</strong>. Renueva antes de esa fecha para evitar interrupciones en el servicio.',
                'cta' => 'Renovar ahora',
                'url' => '{{app_url}}/app/billing/change-plan',
                'color' => '#f59e0b',
            ],
            EmailTemplate::TRIGGER_TRIAL_ENDING => [
                'name' => 'Prueba gratuita próxima a finalizar',
                'subject' => 'Tu prueba de {{app_name}} termina el {{trial_end_date}}',
                'title' => 'Tu prueba gratuita está por terminar',
                'body' => 'Hola <strong>{{user_name}}</strong>. Tu prueba gratuita del plan <strong>{{plan_name}}</strong> termina el <strong>{{trial_end_date}}</strong>. Para mantener activo tu espacio de trabajo sin interrupciones, selecciona un plan antes de que finalice la prueba.',
                'cta' => 'Elegir un plan',
                'url' => '{{app_url}}/app/billing/change-plan',
                'color' => '#6366f1',
            ],
            EmailTemplate::TRIGGER_PAYMENT_SUCCESS => [
                'name' => 'Pago recibido',
                'subject' => 'Recibimos tu pago de {{amount}} — Plan {{plan_name}}',
                'title' => 'Pago recibido correctamente',
                'body' => 'Hola <strong>{{user_name}}</strong>. Recibimos tu pago de <strong>{{amount}}</strong> correspondiente al plan <strong>{{plan_name}}</strong>. Tu suscripción está activa hasta el <strong>{{expiry_date}}</strong>. Gracias por confiar en PRODEX.',
                'cta' => 'Ver suscripción',
                'url' => '{{app_url}}/app/billing/current-plan',
                'color' => '#10b981',
            ],
            EmailTemplate::TRIGGER_PAYMENT_FAILED => [
                'name' => 'Pago fallido',
                'subject' => 'No se pudo procesar el pago de tu plan {{plan_name}}',
                'title' => 'No se pudo procesar el pago',
                'body' => 'Hola <strong>{{user_name}}</strong>. No pudimos procesar tu pago de <strong>{{amount}}</strong> para el plan <strong>{{plan_name}}</strong>. Revisa el método de pago e inténtalo nuevamente. Si el problema continúa, contacta a soporte.',
                'cta' => 'Revisar pago',
                'url' => '{{app_url}}/app/billing/history',
                'color' => '#ef4444',
            ],
            EmailTemplate::TRIGGER_PLAN_ENDED => [
                'name' => 'Plan finalizado',
                'subject' => 'Tu plan {{plan_name}} finalizó — vuelve a suscribirte para continuar',
                'title' => 'Tu plan ha finalizado',
                'body' => 'Hola <strong>{{user_name}}</strong>. Tu suscripción al plan <strong>{{plan_name}}</strong> finalizó el <strong>{{expiry_date}}</strong>. Puedes volver a suscribirte cuando quieras para recuperar el acceso completo a las funciones de PRODEX.',
                'cta' => 'Volver a suscribirme',
                'url' => '{{resubscribe_url}}',
                'color' => '#6366f1',
            ],
            EmailTemplate::TRIGGER_TENANT_PENDING => [
                'name' => 'Tenant pendiente de aprobación',
                'subject' => 'Nuevo tenant pendiente de aprobación: {{user_name}}',
                'title' => 'Nuevo registro pendiente de aprobación',
                'body' => 'Se recibió un nuevo registro para <strong>{{app_name}}</strong>. Usuario: <strong>{{user_name}}</strong>. Revisa la información y el pago correspondiente desde el SuperAdmin antes de aprobar el tenant.',
                'cta' => 'Abrir SuperAdmin',
                'url' => '{{app_url}}/super',
                'color' => '#f59e0b',
            ],
            EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW => [
                'name' => 'Cuenta en revisión',
                'subject' => 'Tu cuenta está en revisión — {{app_name}}',
                'title' => 'Estamos revisando tu cuenta',
                'body' => 'Hola <strong>{{user_name}}</strong>. Recibimos tu registro y actualmente estamos revisando la información. Te notificaremos cuando tu espacio de trabajo esté aprobado y listo para utilizarse.',
                'cta' => 'Ir a {{app_name}}',
                'url' => '{{app_url}}',
                'color' => '#f59e0b',
            ],
            EmailTemplate::TRIGGER_TENANT_APPROVED => [
                'name' => 'Tenant aprobado',
                'subject' => 'Tu espacio de trabajo está listo — {{app_name}}',
                'title' => 'Tu espacio de trabajo está listo',
                'body' => 'Hola <strong>{{user_name}}</strong>. Tu cuenta fue aprobada y tu espacio de trabajo ya está disponible. Puedes iniciar sesión y comenzar a configurar tu empresa en PRODEX.',
                'cta' => 'Iniciar sesión',
                'url' => '{{tenant_url}}',
                'color' => '#10b981',
            ],
            EmailTemplate::TRIGGER_TENANT_REJECTED => [
                'name' => 'Tenant rechazado',
                'subject' => 'Actualización de tu registro — {{app_name}}',
                'title' => 'Actualización de tu registro',
                'body' => 'Hola <strong>{{user_name}}</strong>. En este momento no fue posible aprobar tu registro. Si necesitas más información o consideras que debemos revisar nuevamente tu solicitud, comunícate con soporte.',
                'cta' => 'Contactar soporte',
                'url' => '{{app_url}}',
                'color' => '#ef4444',
            ],
            EmailTemplate::TRIGGER_SUPPORT_TICKET_CREATED => [
                'name' => 'Ticket de soporte creado',
                'subject' => 'Recibimos tu ticket {{ticket_number}}',
                'title' => 'Recibimos tu solicitud de soporte',
                'body' => 'Hola <strong>{{user_name}}</strong>. Tu ticket <strong>{{ticket_number}}</strong> fue creado correctamente. Nuestro equipo podrá revisar el historial del caso y responderte desde el centro de soporte.',
                'cta' => 'Ver ticket',
                'url' => '{{ticket_url}}',
                'color' => '#6366f1',
            ],
            EmailTemplate::TRIGGER_SUPPORT_TICKET_REPLY => [
                'name' => 'Nueva respuesta en ticket de soporte',
                'subject' => 'Nueva respuesta en el ticket {{ticket_number}}',
                'title' => 'Tienes una nueva respuesta',
                'body' => 'Hola <strong>{{user_name}}</strong>. Hay una nueva respuesta en tu ticket <strong>{{ticket_number}}</strong>. Abre el ticket para leer el mensaje completo y continuar la conversación.',
                'cta' => 'Ver respuesta',
                'url' => '{{ticket_url}}',
                'color' => '#6366f1',
            ],
            EmailTemplate::TRIGGER_SUPPORT_TICKET_STATUS => [
                'name' => 'Estado de ticket actualizado',
                'subject' => 'El ticket {{ticket_number}} ahora está {{ticket_status}}',
                'title' => 'El estado de tu ticket cambió',
                'body' => 'Hola <strong>{{user_name}}</strong>. El estado del ticket <strong>{{ticket_number}}</strong> cambió a <strong>{{ticket_status}}</strong>. Puedes abrir el ticket para revisar el historial completo.',
                'cta' => 'Ver ticket',
                'url' => '{{ticket_url}}',
                'color' => '#6366f1',
            ],
        ];

        foreach ($templates as $trigger => $data) {
            EmailTemplate::updateOrCreate(
                ['trigger_key' => $trigger],
                [
                    'name' => $data['name'],
                    'subject' => $data['subject'],
                    'body_html' => $this->wrapper($data['title'], $data['body'], $data['cta'], $data['url'], $data['color']),
                ]
            );
        }
    }

    private function wrapper(string $title, string $body, string $cta, string $url, string $accent): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notificación de PRODEX</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;"><tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow:hidden;">
<tr><td style="background:{$accent};padding:32px 40px;text-align:center;"><h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;">{{app_name}}</h1></td></tr>
<tr><td style="padding:40px;"><h2 style="margin:0 0 16px;font-size:20px;color:#1e293b;">{$title}</h2><p style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.7;">{$body}</p><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:8px;background:{$accent};"><a href="{$url}" style="display:inline-block;padding:14px 32px;color:#fff;text-decoration:none;font-weight:600;font-size:15px;">{$cta}</a></td></tr></table></td></tr>
<tr><td style="padding:24px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;"><p style="margin:0;font-size:13px;color:#94a3b8;">Este correo fue enviado por <a href="{{app_url}}" style="color:{$accent};text-decoration:none;">{{app_name}}</a>.</p></td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
