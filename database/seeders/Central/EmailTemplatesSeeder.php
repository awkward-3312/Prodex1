<?php

namespace Database\Seeders\Central;

use App\Models\Central\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'trigger_key' => EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED,
                'name'        => 'Subscription Expired',
                'subject'     => 'Your {{plan_name}} subscription has expired',
                'body_html'   => $this->subscriptionExpiredTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_EXPIRING_SOON,
                'name'        => 'Subscription Expiring Soon',
                'subject'     => 'Your {{plan_name}} subscription expires on {{expiry_date}}',
                'body_html'   => $this->expiringSoonTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_TRIAL_ENDING,
                'name'        => 'Trial Ending Soon',
                'subject'     => 'Your {{app_name}} trial ends on {{trial_end_date}}',
                'body_html'   => $this->trialEndingTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_PAYMENT_SUCCESS,
                'name'        => 'Payment Successful',
                'subject'     => 'Payment of {{amount}} received — {{plan_name}} plan',
                'body_html'   => $this->paymentSuccessTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_PAYMENT_FAILED,
                'name'        => 'Payment Failed',
                'subject'     => 'Payment failed for your {{plan_name}} subscription',
                'body_html'   => $this->paymentFailedTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_PLAN_ENDED,
                'name'        => 'Plan Has Ended',
                'subject'     => 'Your {{plan_name}} plan has ended — resubscribe to continue',
                'body_html'   => $this->planEndedTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_TENANT_PENDING,
                'name'        => 'Tenant Pending Approval',
                'subject'     => 'New Tenant Pending Approval: {{user_name}}',
                'body_html'   => $this->tenantPendingTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_TENANT_UNDER_REVIEW,
                'name'        => 'Account Under Review',
                'subject'     => 'Your account is under review — {{app_name}}',
                'body_html'   => $this->tenantUnderReviewTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_TENANT_APPROVED,
                'name'        => 'Tenant Approved',
                'subject'     => 'Your workspace is ready — {{app_name}}',
                'body_html'   => $this->tenantApprovedTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_TENANT_REJECTED,
                'name'        => 'Tenant Rejected',
                'subject'     => 'Registration update — {{app_name}}',
                'body_html'   => $this->tenantRejectedTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_SUPPORT_TICKET_CREATED,
                'name'        => 'Support Ticket Created',
                'subject'     => 'We received your ticket {{ticket_number}}',
                'body_html'   => $this->supportTicketCreatedTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_SUPPORT_TICKET_REPLY,
                'name'        => 'Support Ticket Reply',
                'subject'     => 'New reply on ticket {{ticket_number}}',
                'body_html'   => $this->supportTicketReplyTemplate(),
            ],
            [
                'trigger_key' => EmailTemplate::TRIGGER_SUPPORT_TICKET_STATUS,
                'name'        => 'Support Ticket Status Changed',
                'subject'     => 'Ticket {{ticket_number}} is now {{ticket_status}}',
                'body_html'   => $this->supportTicketStatusTemplate(),
            ],
        ];

        foreach ($templates as $tpl) {
            EmailTemplate::updateOrCreate(
                ['trigger_key' => $tpl['trigger_key']],
                $tpl
            );
        }
    }

    protected function baseWrapper(string $content, string $accentColor = '#6366f1'): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.08); overflow:hidden;">

<!-- Header -->
<tr>
<td style="background:{$accentColor}; padding:32px 40px; text-align:center;">
<h1 style="margin:0; color:#ffffff; font-size:22px; font-weight:700;">{{app_name}}</h1>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:40px;">
{$content}
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:24px 40px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:center;">
<p style="margin:0; font-size:13px; color:#94a3b8;">This email was sent by <a href="{{app_url}}" style="color:{$accentColor}; text-decoration:none;">{{app_name}}</a></p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    protected function subscriptionExpiredTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Subscription Expired</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Your <strong>{{plan_name}}</strong> subscription expired on <strong>{{expiry_date}}</strong>. Some features may be restricted until you renew your plan.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
To continue enjoying full access, please renew your subscription as soon as possible.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{app_url}}/app/billing/change-plan" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Renew Subscription</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#ef4444');
    }

    protected function expiringSoonTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Subscription Expiring Soon</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Your <strong>{{plan_name}}</strong> subscription will expire on <strong>{{expiry_date}}</strong>. Please renew before that date to avoid any interruption in service.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Renewing is quick and easy — just click the button below.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{app_url}}/app/billing/change-plan" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Renew Now</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#f59e0b');
    }

    protected function trialEndingTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Your Free Trial Is Ending</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Your free trial of the <strong>{{plan_name}}</strong> plan ends on <strong>{{trial_end_date}}</strong>. To keep your workspace active without interruption, please subscribe before your trial expires.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Pick a plan in just a couple of clicks — your data stays exactly where it is.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{app_url}}/app/billing/change-plan" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Choose a Plan</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#6366f1');
    }

    protected function paymentSuccessTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Payment Received</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
We've received your payment of <strong>{{amount}}</strong> for the <strong>{{plan_name}}</strong> plan. Your subscription is active until <strong>{{expiry_date}}</strong>.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Thank you for your continued support!
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#10b981;">
<a href="{{app_url}}/app/billing/current-plan" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">View Subscription</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#10b981');
    }

    protected function paymentFailedTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Payment Failed</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
We were unable to process your payment of <strong>{{amount}}</strong> for the <strong>{{plan_name}}</strong> plan. Your subscription may be affected if payment is not completed.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Please check your payment method and try again. If the issue persists, contact our support team.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#ef4444;">
<a href="{{app_url}}/app/billing/history" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Retry Payment</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#ef4444');
    }

    protected function planEndedTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Your Plan Has Ended</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Your <strong>{{plan_name}}</strong> subscription ended on <strong>{{expiry_date}}</strong> following your cancellation. Your access to premium features has now been revoked.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
We'd love to have you back! Resubscribe anytime to regain full access to all features and continue managing your business seamlessly.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{resubscribe_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Resubscribe Now</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#6366f1');
    }

    protected function tenantPendingTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">New Tenant Pending Approval</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
A new tenant has registered and is waiting for your approval.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:24px;">
<tr>
<td style="padding:12px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%">
<tr><td style="padding:4px 0; font-size:14px; color:#64748b; width:120px;">Company:</td><td style="padding:4px 0; font-size:14px; color:#1e293b; font-weight:600;">{{user_name}}</td></tr>
<tr><td style="padding:4px 0; font-size:14px; color:#64748b;">Subdomain:</td><td style="padding:4px 0; font-size:14px; color:#1e293b; font-weight:600;">{{subdomain}}</td></tr>
<tr><td style="padding:4px 0; font-size:14px; color:#64748b;">Email:</td><td style="padding:4px 0; font-size:14px; color:#1e293b; font-weight:600;">{{user_email}}</td></tr>
<tr><td style="padding:4px 0; font-size:14px; color:#64748b;">Registered:</td><td style="padding:4px 0; font-size:14px; color:#1e293b; font-weight:600;">{{registered_at}}</td></tr>
</table>
</td>
</tr>
</table>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Please review this registration and approve or reject it from the admin dashboard.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{app_url}}/super/dashboard" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Go to Dashboard</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#6366f1');
    }

    protected function tenantUnderReviewTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Account Under Review</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Thank you for registering with <strong>{{app_name}}</strong>! Your account is currently being reviewed by our team.
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
This process usually takes a short time. You will receive another email once your account has been approved and your workspace is ready.
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
If you have any questions, feel free to contact us.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#f59e0b;">
<a href="{{app_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Visit {{app_name}}</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#f59e0b');
    }

    protected function tenantApprovedTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Your Workspace is Ready!</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Great news! Your account has been approved and your workspace is now ready to use.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:24px;">
<tr>
<td style="padding:12px 16px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%">
<tr><td style="padding:4px 0; font-size:14px; color:#64748b; width:120px;">Workspace:</td><td style="padding:4px 0; font-size:14px; color:#1e293b; font-weight:600;"><a href="{{tenant_url}}" style="color:#10b981; text-decoration:none;">{{tenant_url}}</a></td></tr>
<tr><td style="padding:4px 0; font-size:14px; color:#64748b;">Login URL:</td><td style="padding:4px 0; font-size:14px; color:#1e293b; font-weight:600;"><a href="{{login_url}}" style="color:#10b981; text-decoration:none;">{{login_url}}</a></td></tr>
</table>
</td>
</tr>
</table>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Click the button below to log in and start using your workspace.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#10b981;">
<a href="{{login_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Go to Workspace</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#10b981');
    }

    protected function tenantRejectedTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Registration Update</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
We've reviewed your registration with <strong>{{app_name}}</strong> and unfortunately we are unable to approve your account at this time.
</p>
<div style="margin:0 0 24px; padding:16px; background:#fef2f2; border-radius:8px; border-left:4px solid #ef4444;">
<p style="margin:0 0 4px; font-size:13px; color:#64748b; font-weight:600;">Reason:</p>
<p style="margin:0; font-size:15px; color:#991b1b; line-height:1.6;">{{rejection_reason}}</p>
</div>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
If you believe this was a mistake or would like more information, please contact us.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{app_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">Contact Us</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#ef4444');
    }

    protected function supportTicketCreatedTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">We received your request</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Thanks for reaching out. Your support ticket <strong>{{ticket_number}}</strong> — "{{ticket_subject}}" — has been created and our team will get back to you as soon as possible.
</p>
<div style="margin:0 0 24px; padding:14px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; white-space:pre-wrap; font-size:14px; color:#334155;">{{ticket_message}}</div>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{ticket_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">View Ticket</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#6366f1');
    }

    protected function supportTicketReplyTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">You have a new reply</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Our support team replied to your ticket <strong>{{ticket_number}}</strong> — "{{ticket_subject}}".
</p>
<div style="margin:0 0 24px; padding:14px 16px; background:#eef2ff; border-radius:8px; border:1px solid #c7d2fe; white-space:pre-wrap; font-size:14px; color:#334155;">{{ticket_message}}</div>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#6366f1;">
<a href="{{ticket_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">View &amp; Reply</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#6366f1');
    }

    protected function supportTicketStatusTemplate(): string
    {
        $content = <<<'BODY'
<h2 style="margin:0 0 16px; font-size:20px; color:#1e293b;">Ticket status updated</h2>
<p style="margin:0 0 16px; font-size:15px; color:#475569; line-height:1.6;">
Hi <strong>{{user_name}}</strong>,
</p>
<p style="margin:0 0 24px; font-size:15px; color:#475569; line-height:1.6;">
Your support ticket <strong>{{ticket_number}}</strong> — "{{ticket_subject}}" — is now marked as <strong>{{ticket_status}}</strong>.
</p>
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="border-radius:8px; background:#10b981;">
<a href="{{ticket_url}}" style="display:inline-block; padding:14px 32px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">View Ticket</a>
</td>
</tr>
</table>
BODY;
        return $this->baseWrapper($content, '#10b981');
    }
}
