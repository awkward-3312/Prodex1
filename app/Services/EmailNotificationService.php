<?php

namespace App\Services;

use App\Models\Central\CentralLanguage;
use App\Models\Central\EmailTemplate;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    /**
     * Send a notification email for the given trigger key.
     *
     * @param string $triggerKey  One of EmailTemplate::TRIGGERS
     * @param Tenant $tenant      The tenant this notification is about
     * @param array  $extraVars   Additional/override variables for the template
     * @param TenantSubscription|null $subscription  The subscription this notification is about.
     *                            A tenant can have several subscription rows (old trial, pending
     *                            upgrade, …) — pass the relevant one so dates/plan resolve correctly.
     */
    public static function send(string $triggerKey, Tenant $tenant, array $extraVars = [], ?string $locale = null, ?TenantSubscription $subscription = null): bool
    {
        try {
            $template = EmailTemplate::with('translations')
                ->where('trigger_key', $triggerKey)
                ->where('is_active', true)
                ->first();

            if (! $template) {
                return false;
            }

            // Resolve locale: use passed locale, tenant's locale, or null (default)
            $locale = $locale ?? $tenant->locale ?? null;

            // Don't pass the default locale — let it fall back naturally
            if ($locale && $locale === CentralLanguage::defaultLocale()) {
                $locale = null;
            }

            $subscription = $subscription ?? $tenant->activeSubscription ?? $tenant->subscription;
            $plan         = $subscription?->plan;

            $variables = array_merge([
                '{{user_name}}'   => $tenant->company_name ?? $tenant->id,
                '{{user_email}}'  => $tenant->admin_email ?? '',
                '{{expiry_date}}' => $subscription?->ends_at?->format('M d, Y') ?? 'N/A',
                '{{trial_end_date}}' => $subscription?->trial_ends_at?->format('M d, Y') ?? 'N/A',
                '{{amount}}'      => $subscription ? '$' . number_format((float) $subscription->amount, 2) : 'N/A',
                '{{plan_name}}'   => $plan?->name ?? 'N/A',
                '{{app_name}}'    => config('app.name', 'Stocky'),
                '{{app_url}}'     => config('app.url', 'http://localhost'),
            ], $extraVars);

            $recipientEmail = $variables['{{user_email}}'];

            if (empty($recipientEmail)) {
                Log::warning("EmailNotification: no admin_email for tenant {$tenant->id}, skipping {$triggerKey}");
                return false;
            }

            $subject = $template->renderSubject($variables, $locale);
            $html    = $template->render($variables, $locale);

            Mail::send([], [], function ($message) use ($recipientEmail, $subject, $html, $variables) {
                $message->to($recipientEmail)
                    ->subject($subject)
                    ->html($html);
            });

            Log::info("EmailNotification: sent [{$triggerKey}] to {$recipientEmail}" . ($locale ? " [locale:{$locale}]" : ''));

            return true;
        } catch (\Throwable $e) {
            Log::error("EmailNotification: failed to send [{$triggerKey}] — {$e->getMessage()}", [
                'tenant_id' => $tenant->id,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Shorthand: subscription expired.
     */
    public static function subscriptionExpired(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED, $tenant, $extra);
    }

    /**
     * Shorthand: subscription expiring soon.
     */
    public static function expiringSoon(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_EXPIRING_SOON, $tenant, $extra);
    }

    /**
     * Shorthand: trial ending soon.
     */
    public static function trialEnding(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_TRIAL_ENDING, $tenant, $extra);
    }

    /**
     * Shorthand: payment success.
     */
    public static function paymentSuccess(Tenant $tenant, array $extra = [], ?TenantSubscription $subscription = null): bool
    {
        return static::send(EmailTemplate::TRIGGER_PAYMENT_SUCCESS, $tenant, $extra, null, $subscription);
    }

    /**
     * Shorthand: payment failed.
     */
    public static function paymentFailed(Tenant $tenant, array $extra = [], ?TenantSubscription $subscription = null): bool
    {
        return static::send(EmailTemplate::TRIGGER_PAYMENT_FAILED, $tenant, $extra, null, $subscription);
    }

    /**
     * Shorthand: plan ended (cancelled subscription reached expiry).
     */
    public static function planEnded(Tenant $tenant, array $extra = []): bool
    {
        $domain = $tenant->domains->first()?->domain ?? config('app.url');
        $resubscribeUrl = "https://{$domain}/app/billing/change-plan";

        $extra = array_merge([
            '{{resubscribe_url}}' => $resubscribeUrl,
        ], $extra);

        return static::send(EmailTemplate::TRIGGER_PLAN_ENDED, $tenant, $extra);
    }

    /**
     * Resolve the tenant's preferred locale (if set).
     */
    public static function tenantLocale(Tenant $tenant): ?string
    {
        return $tenant->locale ?? null;
    }
}
