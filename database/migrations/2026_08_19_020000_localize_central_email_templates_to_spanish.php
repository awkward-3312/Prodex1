<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = [
            'subscription_expired' => [
                'name' => 'Suscripción vencida',
                'subject' => 'Tu suscripción {{plan_name}} ha vencido',
                'title' => 'Tu suscripción ha vencido',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Tu suscripción al plan <strong>{{plan_name}}</strong> venció el <strong>{{expiry_date}}</strong>. Algunas funciones pueden quedar limitadas hasta que renueves tu plan.',
                'cta' => 'Renovar suscripción',
                'url' => '{{tenant_url}}/app/billing/change-plan',
                'accent' => '#ef4444',
            ],
            'expiring_soon' => [
                'name' => 'Suscripción próxima a vencer',
                'subject' => 'Tu suscripción {{plan_name}} vence el {{expiry_date}}',
                'title' => 'Tu suscripción está próxima a vencer',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Tu suscripción al plan <strong>{{plan_name}}</strong> vence el <strong>{{expiry_date}}</strong>. Renueva antes de esa fecha para evitar interrupciones en el acceso a tu espacio de trabajo.',
                'cta' => 'Renovar ahora',
                'url' => '{{tenant_url}}/app/billing/change-plan',
                'accent' => '#f59e0b',
            ],
            'trial_ending' => [
                'name' => 'Prueba gratuita próxima a finalizar',
                'subject' => 'Tu período de prueba de {{app_name}} finaliza el {{trial_end_date}}',
                'title' => 'Tu período de prueba está por finalizar',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Tu período de prueba del plan <strong>{{plan_name}}</strong> finaliza el <strong>{{trial_end_date}}</strong>. Elige un plan antes de esa fecha para mantener tu espacio de trabajo activo y conservar el acceso sin interrupciones.',
                'cta' => 'Elegir un plan',
                'url' => '{{tenant_url}}/app/billing/change-plan',
                'accent' => '#6366f1',
            ],
            'payment_success' => [
                'name' => 'Pago recibido',
                'subject' => 'Pago de {{amount}} recibido — Plan {{plan_name}}',
                'title' => 'Pago recibido correctamente',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Hemos recibido tu pago de <strong>{{amount}}</strong> correspondiente al plan <strong>{{plan_name}}</strong>. Tu suscripción se encuentra activa hasta el <strong>{{expiry_date}}</strong>.<br><br>Gracias por confiar en {{app_name}}.',
                'cta' => 'Ver mi suscripción',
                'url' => '{{tenant_url}}/app/billing/current-plan',
                'accent' => '#10b981',
            ],
            'payment_failed' => [
                'name' => 'Pago no completado',
                'subject' => 'No se pudo completar el pago de tu plan {{plan_name}}',
                'title' => 'No se pudo completar el pago',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>No fue posible completar el pago de <strong>{{amount}}</strong> correspondiente al plan <strong>{{plan_name}}</strong>. Revisa el estado de tu pago o vuelve a intentarlo. Si necesitas ayuda, comunícate con nuestro equipo de soporte.',
                'cta' => 'Revisar pagos',
                'url' => '{{tenant_url}}/app/billing/history',
                'accent' => '#ef4444',
            ],
            'plan_ended' => [
                'name' => 'Plan finalizado',
                'subject' => 'Tu plan {{plan_name}} ha finalizado',
                'title' => 'Tu plan ha finalizado',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Tu suscripción al plan <strong>{{plan_name}}</strong> finalizó el <strong>{{expiry_date}}</strong>. Para recuperar el acceso a las funciones incluidas en tu plan, puedes volver a suscribirte cuando lo desees.',
                'cta' => 'Volver a suscribirme',
                'url' => '{{resubscribe_url}}',
                'accent' => '#6366f1',
            ],
            'tenant_pending' => [
                'name' => 'Nuevo registro pendiente de aprobación',
                'subject' => 'Nuevo registro pendiente de aprobación: {{user_name}}',
                'title' => 'Nuevo registro pendiente',
                'body' => 'Se ha recibido un nuevo registro en {{app_name}}.<br><br><strong>Empresa:</strong> {{user_name}}<br><strong>Correo:</strong> {{user_email}}<br><strong>Subdominio:</strong> {{subdomain}}<br><strong>Fecha de registro:</strong> {{registered_at}}<br><br>Ingresa al SuperAdmin para revisar la solicitud y continuar con el proceso de aprobación.',
                'cta' => 'Abrir SuperAdmin',
                'url' => '{{app_url}}/super/tenants?status=pending',
                'accent' => '#6366f1',
            ],
            'tenant_under_review' => [
                'name' => 'Cuenta en revisión',
                'subject' => 'Tu registro está en revisión — {{app_name}}',
                'title' => 'Estamos revisando tu registro',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Hemos recibido tu información y tu registro se encuentra en revisión. Te notificaremos por correo electrónico cuando el proceso haya finalizado.<br><br>No necesitas realizar ninguna acción adicional por el momento.',
                'cta' => null,
                'url' => null,
                'accent' => '#f59e0b',
            ],
            'tenant_approved' => [
                'name' => 'Cuenta aprobada',
                'subject' => 'Tu espacio de trabajo ya está listo — {{app_name}}',
                'title' => 'Tu cuenta ha sido aprobada',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Tu espacio de trabajo en {{app_name}} ya está listo.<br><br><strong>Dirección de tu espacio:</strong> <a href="{{tenant_url}}">{{tenant_url}}</a><br><strong>Acceso:</strong> <a href="{{login_url}}">{{login_url}}</a><br><br>Utiliza el correo y la contraseña que registraste para iniciar sesión.',
                'cta' => 'Ingresar a mi cuenta',
                'url' => '{{login_url}}',
                'accent' => '#10b981',
            ],
            'tenant_rejected' => [
                'name' => 'Registro no aprobado',
                'subject' => 'Actualización sobre tu registro — {{app_name}}',
                'title' => 'Actualización de tu registro',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>En esta ocasión no fue posible aprobar tu registro.<br><br><strong>Motivo:</strong> {{rejection_reason}}<br><br>Si consideras que existe un error o necesitas más información, puedes comunicarte con nuestro equipo de soporte.',
                'cta' => null,
                'url' => null,
                'accent' => '#ef4444',
            ],
            'support_ticket_created' => [
                'name' => 'Ticket de soporte recibido',
                'subject' => 'Recibimos tu ticket {{ticket_number}}',
                'title' => 'Recibimos tu solicitud de soporte',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Tu solicitud fue registrada correctamente.<br><br><strong>Ticket:</strong> {{ticket_number}}<br><strong>Asunto:</strong> {{ticket_subject}}<br><strong>Estado:</strong> {{ticket_status}}<br><br>Nuestro equipo revisará tu solicitud y responderá lo antes posible.',
                'cta' => 'Ver ticket',
                'url' => '{{ticket_url}}',
                'accent' => '#6366f1',
            ],
            'support_ticket_reply' => [
                'name' => 'Nueva respuesta de soporte',
                'subject' => 'Nueva respuesta en el ticket {{ticket_number}}',
                'title' => 'Tienes una nueva respuesta de soporte',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>Nuestro equipo respondió a tu ticket <strong>{{ticket_number}}</strong> — {{ticket_subject}}.<br><br><div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;white-space:pre-wrap;">{{ticket_message}}</div>',
                'cta' => 'Ver conversación',
                'url' => '{{ticket_url}}',
                'accent' => '#6366f1',
            ],
            'support_ticket_status' => [
                'name' => 'Cambio de estado de ticket',
                'subject' => 'El ticket {{ticket_number}} ahora está {{ticket_status}}',
                'title' => 'Estado de tu ticket actualizado',
                'body' => 'Hola <strong>{{user_name}}</strong>,<br><br>El estado de tu ticket <strong>{{ticket_number}}</strong> — {{ticket_subject}} ha cambiado a <strong>{{ticket_status}}</strong>.',
                'cta' => 'Ver ticket',
                'url' => '{{ticket_url}}',
                'accent' => '#6366f1',
            ],
        ];

        foreach ($templates as $trigger => $data) {
            DB::connection('central')->table('email_templates')
                ->where('trigger_key', $trigger)
                ->update([
                    'name' => $data['name'],
                    'subject' => $data['subject'],
                    'body_html' => $this->wrap($data),
                    'updated_at' => now(),
                ]);
        }
    }

    private function wrap(array $data): string
    {
        $button = '';
        if (! empty($data['cta']) && ! empty($data['url'])) {
            $button = '<p style="margin:24px 0 0;"><a href="' . $data['url'] . '" style="display:inline-block;padding:12px 22px;background:' . $data['accent'] . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">' . $data['cta'] . '</a></p>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . $data['title'] . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;"><tr><td align="center" style="padding:40px 20px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">'
            . '<tr><td style="background:' . $data['accent'] . ';padding:28px 40px;text-align:center;"><h1 style="margin:0;color:#fff;font-size:22px;">{{app_name}}</h1></td></tr>'
            . '<tr><td style="padding:36px 40px;"><h2 style="margin:0 0 18px;color:#1e293b;font-size:21px;">' . $data['title'] . '</h2><div style="font-size:15px;color:#475569;line-height:1.65;">' . $data['body'] . '</div>' . $button . '</td></tr>'
            . '<tr><td style="padding:22px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;"><p style="margin:0;font-size:13px;color:#94a3b8;">Este correo fue enviado por <a href="{{app_url}}" style="color:' . $data['accent'] . ';text-decoration:none;">{{app_name}}</a>.</p></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public function down(): void
    {
        // Intentionally left empty. Email templates are editable business data;
        // a rollback must not overwrite changes made later by an administrator.
    }
};
