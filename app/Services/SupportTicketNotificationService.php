<?php

namespace App\Services;

use App\Models\Central\EmailTemplate;
use App\Models\Central\GeneralSetting;
use App\Models\Central\SupportTicket;
use App\Models\Central\SupportTicketMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends support-ticket email notifications in both directions:
 *   - tenant -> super admin  (new ticket, tenant reply)   — internal inbox email
 *   - super admin -> tenant  (admin reply, status change)  — template-driven, customizable
 *
 * All sends are best-effort and never throw into the request lifecycle.
 */
class SupportTicketNotificationService
{
    // ── Tenant actions notify the admin inbox ───────────────────────────

    public static function ticketCreated(SupportTicket $ticket, SupportTicketMessage $message): void
    {
        self::notifyAdmin(
            $ticket,
            'New support ticket: ' . $ticket->ticket_number . ' — ' . $ticket->subject,
            'A new support ticket has been opened.',
            $message->body
        );

        // Confirmation copy to the tenant (template-driven, optional).
        self::notifyTenant($ticket, EmailTemplate::TRIGGER_SUPPORT_TICKET_CREATED, $message->body, [
            'subject'  => 'We received your ticket ' . $ticket->ticket_number,
            'heading'  => 'Thanks — we received your request',
            'intro'    => 'Our support team will get back to you as soon as possible.',
        ]);
    }

    public static function tenantReplied(SupportTicket $ticket, SupportTicketMessage $message): void
    {
        self::notifyAdmin(
            $ticket,
            'New reply on ticket ' . $ticket->ticket_number,
            'The tenant added a reply to an existing ticket.',
            $message->body
        );
    }

    // ── Admin actions notify the tenant ─────────────────────────────────

    public static function adminReplied(SupportTicket $ticket, SupportTicketMessage $message): void
    {
        self::notifyTenant($ticket, EmailTemplate::TRIGGER_SUPPORT_TICKET_REPLY, $message->body, [
            'subject' => 'Support replied to ticket ' . $ticket->ticket_number,
            'heading' => 'You have a new reply',
            'intro'   => 'Our support team replied to your ticket "' . $ticket->subject . '".',
        ]);
    }

    /**
     * A super admin opened a ticket on behalf of a tenant — let the tenant know.
     * Uses the built-in layout directly: the "ticket created" template is worded
     * for tenant-initiated tickets ("we received your request") and would mislead here.
     */
    public static function ticketCreatedByAdmin(SupportTicket $ticket, SupportTicketMessage $message): void
    {
        try {
            $recipient = $ticket->opened_by_email;
            if (empty($recipient)) {
                return;
            }

            $url = self::tenantUrl($ticket);
            $subject = 'Support ticket ' . $ticket->ticket_number . ' — ' . $ticket->subject;
            $html = self::defaultHtml(
                $ticket,
                'Our support team opened a ticket for your account. You can follow the conversation and reply from your support area.',
                $message->body,
                $url,
                'View ticket',
                'A support ticket was opened for you'
            );

            Mail::send([], [], function ($mail) use ($recipient, $subject, $html) {
                $mail->to($recipient)->subject($subject)->html($html);
            });
        } catch (\Throwable $e) {
            Log::error("SupportTicketNotification: admin-created ticket notify failed — {$e->getMessage()}", [
                'ticket_id' => $ticket->id,
            ]);
        }
    }

    public static function statusChanged(SupportTicket $ticket): void
    {
        self::notifyTenant($ticket, EmailTemplate::TRIGGER_SUPPORT_TICKET_STATUS, null, [
            'subject' => 'Ticket ' . $ticket->ticket_number . ' is now ' . ucfirst($ticket->status),
            'heading' => 'Ticket status updated',
            'intro'   => 'Your ticket "' . $ticket->subject . '" is now marked as ' . ucfirst($ticket->status) . '.',
        ]);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * Email the platform support inbox (+ assigned agent, if any).
     */
    private static function notifyAdmin(SupportTicket $ticket, string $subject, string $intro, ?string $body): void
    {
        try {
            $recipients = [];

            $inbox = GeneralSetting::instance()->email ?: config('mail.from.address');
            if ($inbox) {
                $recipients[] = $inbox;
            }

            $ticket->loadMissing('assignedAdmin');
            if ($ticket->assignedAdmin?->email) {
                $recipients[] = $ticket->assignedAdmin->email;
            }

            $recipients = array_values(array_unique(array_filter($recipients)));

            if (empty($recipients)) {
                return;
            }

            $url = self::adminUrl($ticket);
            $html = self::defaultHtml($ticket, $intro, $body, $url, 'View ticket');

            Mail::send([], [], function ($message) use ($recipients, $subject, $html) {
                $message->to($recipients)->subject($subject)->html($html);
            });
        } catch (\Throwable $e) {
            Log::error("SupportTicketNotification: admin notify failed — {$e->getMessage()}", [
                'ticket_id' => $ticket->id,
            ]);
        }
    }

    /**
     * Email the tenant who opened the ticket. Uses the customizable email
     * template for the trigger when one is active, otherwise a built-in layout.
     */
    private static function notifyTenant(SupportTicket $ticket, string $triggerKey, ?string $body, array $defaults): void
    {
        try {
            $recipient = $ticket->opened_by_email;
            if (empty($recipient)) {
                return;
            }

            $url = self::tenantUrl($ticket);

            $variables = [
                '{{user_name}}'      => $ticket->opened_by_name ?? $ticket->tenantName(),
                '{{user_email}}'     => $recipient,
                '{{app_name}}'       => config('app.name', 'Stocky'),
                '{{app_url}}'        => config('app.url', 'http://localhost'),
                '{{ticket_number}}'  => $ticket->ticket_number,
                '{{ticket_subject}}' => $ticket->subject,
                '{{ticket_status}}'  => ucfirst($ticket->status),
                '{{ticket_message}}' => $body ?? '',
                '{{ticket_url}}'     => $url,
            ];

            $template = EmailTemplate::with('translations')
                ->where('trigger_key', $triggerKey)
                ->where('is_active', true)
                ->first();

            if ($template) {
                $subject = $template->renderSubject($variables);
                $html    = $template->render($variables);
            } else {
                $subject = $defaults['subject'];
                $html    = self::defaultHtml(
                    $ticket,
                    $defaults['intro'] ?? '',
                    $body,
                    $url,
                    'View ticket',
                    $defaults['heading'] ?? null
                );
            }

            Mail::send([], [], function ($message) use ($recipient, $subject, $html) {
                $message->to($recipient)->subject($subject)->html($html);
            });
        } catch (\Throwable $e) {
            Log::error("SupportTicketNotification: tenant notify failed — {$e->getMessage()}", [
                'ticket_id' => $ticket->id,
            ]);
        }
    }

    private static function adminUrl(SupportTicket $ticket): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/super/support/' . $ticket->id;
    }

    private static function tenantUrl(SupportTicket $ticket): string
    {
        $base = '';
        try {
            $base = $ticket->tenant?->getTenantUrl() ?? '';
        } catch (\Throwable $e) {
            $base = '';
        }

        return $base ? $base . '/app/support/tickets/' . $ticket->id : '';
    }

    /**
     * Minimal, self-contained HTML email used when no custom template applies.
     */
    private static function defaultHtml(
        SupportTicket $ticket,
        string $intro,
        ?string $body,
        string $url,
        string $cta,
        ?string $heading = null
    ): string {
        $appName = e(config('app.name', 'Stocky'));
        $heading = e($heading ?? 'Support Ticket Update');
        $intro   = e($intro);
        $number  = e($ticket->ticket_number);
        $subject = e($ticket->subject);
        $status  = e(ucfirst($ticket->status));
        $bodyHtml = $body ? '<div style="margin:16px 0;padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e5e7eb;white-space:pre-wrap;">' . nl2br(e($body)) . '</div>' : '';
        $button = $url ? '<p style="margin:24px 0;"><a href="' . e($url) . '" style="background:#0d6efd;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;display:inline-block;">' . e($cta) . '</a></p>' : '';

        return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#111827;">
    <h2 style="margin:0 0 8px;">{$heading}</h2>
    <p style="color:#374151;">{$intro}</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">
        <tr><td style="padding:4px 0;color:#6b7280;width:120px;">Ticket</td><td style="padding:4px 0;font-weight:600;">{$number}</td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Subject</td><td style="padding:4px 0;">{$subject}</td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Status</td><td style="padding:4px 0;">{$status}</td></tr>
    </table>
    {$bodyHtml}
    {$button}
    <p style="color:#9ca3af;font-size:12px;margin-top:32px;">— {$appName} Support</p>
</div>
HTML;
    }
}
