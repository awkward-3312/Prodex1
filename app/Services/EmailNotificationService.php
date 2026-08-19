<?php

namespace App\Services;

use App\Models\Central\CentralLanguage;
use App\Models\Central\EmailTemplate;
use App\Models\Central\GeneralSetting;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
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

            $locale = $locale ?? $tenant->locale ?? null;
            if ($locale && $locale === CentralLanguage::defaultLocale()) {
                $locale = null;
            }

            $subscription = $subscription ?? $tenant->activeSubscription ?? $tenant->subscription;
            $plan = $subscription?->plan;
            $settings = GeneralSetting::instance();

            $tenantUrl = '';
            try {
                $tenantUrl = rtrim((string) $tenant->getTenantUrl(), '/');
            } catch (\Throwable $e) {
                $domain = $tenant->domains->first()?->domain;
                if ($domain) {
                    $tenantUrl = 'https://' . $domain;
                }
            }

            $currencySymbol = (string) ($settings->currency_symbol ?: 'L.');
            $currencyCode = (string) ($settings->currency_code ?: 'HNL');
            $amount = $subscription
                ? trim($currencySymbol . number_format((float) $subscription->amount, 2) . ' ' . $currencyCode)
                : 'N/D';

            $variables = array_merge([
                '{{user_name}}' => $tenant->company_name ?? $tenant->id,
                '{{user_email}}' => $tenant->admin_email ?? '',
                '{{expiry_date}}' => $subscription?->ends_at?->format('d/m/Y') ?? 'N/D',
                '{{trial_end_date}}' => $subscription?->trial_ends_at?->format('d/m/Y') ?? 'N/D',
                '{{amount}}' => $amount,
                '{{plan_name}}' => $plan?->name ?? 'N/D',
                '{{app_name}}' => $settings->app_name ?: config('app.name', 'PRODEX'),
                '{{app_url}}' => rtrim((string) config('app.url', 'https://prodexhub.cloud'), '/'),
                '{{tenant_url}}' => $tenantUrl,
                '{{login_url}}' => $tenantUrl ? $tenantUrl . '/login' : '',
                '{{resubscribe_url}}' => $tenantUrl ? $tenantUrl . '/app/billing/change-plan' : '',
            ], $extraVars);

            $recipientEmail = $variables['{{user_email}}'];
            if (empty($recipientEmail)) {
                Log::warning("EmailNotification: no admin_email for tenant {$tenant->id}, skipping {$triggerKey}");
                return false;
            }

            $subject = $template->renderSubject($variables, $locale);
            $html = $template->render($variables, $locale);

            Mail::send([], [], function ($message) use ($recipientEmail, $subject, $html) {
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

    public static function subscriptionExpired(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED, $tenant, $extra);
    }

    public static function expiringSoon(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_EXPIRING_SOON, $tenant, $extra);
    }

    public static function trialEnding(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_TRIAL_ENDING, $tenant, $extra);
    }

    public static function paymentSuccess(Tenant $tenant, array $extra = [], ?TenantSubscription $subscription = null): bool
    {
        return static::send(EmailTemplate::TRIGGER_PAYMENT_SUCCESS, $tenant, $extra, null, $subscription);
    }

    public static function paymentFailed(Tenant $tenant, array $extra = [], ?TenantSubscription $subscription = null): bool
    {
        return static::send(EmailTemplate::TRIGGER_PAYMENT_FAILED, $tenant, $extra, null, $subscription);
    }

    public static function planEnded(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_PLAN_ENDED, $tenant, $extra);
    }

    public static function tenantLocale(Tenant $tenant): ?string
    {
        return $tenant->locale ?? null;
    }
}
