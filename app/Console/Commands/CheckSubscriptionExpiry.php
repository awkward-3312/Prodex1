<?php

namespace App\Console\Commands;

use App\Models\Central\GeneralSetting;
use App\Models\Central\SmsTemplate;
use App\Models\Central\SubscriptionReminder;
use App\Models\Central\TenantSubscription;
use App\Services\CentralSmsSender;
use App\Services\EmailNotificationService;
use App\Support\LocksBillingSubscription;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSubscriptionExpiry extends Command
{
    use LocksBillingSubscription;

    protected $signature = 'subscriptions:check-expiry';

    protected $description = 'Send subscription expiry / trial reminders and process expired subscriptions';

    public function handle(): int
    {
        $setting = GeneralSetting::instance();

        $this->sendExpiryReminders($setting);
        $this->sendTrialReminders($setting);
        $this->checkExpired();

        return self::SUCCESS;
    }

    /**
     * Active-channel subset that the command can actually deliver to
     * (banner is a passive UI channel handled in the tenant app, not here).
     *
     * @return string[]
     */
    protected function deliverableChannels(GeneralSetting $setting): array
    {
        return array_values(array_intersect($setting->reminderChannels(), ['email', 'sms']));
    }

    /**
     * Send a reminder ahead of paid-subscription expiry, on each configured
     * offset day (e.g. 7, 3, 1 days before ends_at), through every enabled
     * channel.
     */
    protected function sendExpiryReminders(GeneralSetting $setting): void
    {
        if (! $setting->remindersEnabled()) {
            return;
        }

        $channels = $this->deliverableChannels($setting);
        if (empty($channels)) {
            return;
        }

        $offsets   = $setting->reminderOffsets();
        $maxOffset = max($offsets);

        $subscriptions = TenantSubscription::where('status', TenantSubscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '>', now())
            ->whereDate('ends_at', '<=', now()->addDays($maxOffset))
            ->with('tenant', 'plan')
            ->get();

        foreach ($subscriptions as $sub) {
            $tenant = $sub->tenant;
            if (! $tenant) {
                continue;
            }

            $daysOut = $this->daysUntil($sub->ends_at);
            if (! in_array($daysOut, $offsets, true)) {
                continue;
            }

            if (in_array('email', $channels, true)) {
                $this->deliverEmail(
                    $sub, $tenant, SubscriptionReminder::TYPE_EXPIRY, $daysOut, $sub->ends_at,
                    fn () => EmailNotificationService::expiringSoon($tenant),
                    "Expiry email (T-{$daysOut}d)"
                );
            }

            if (in_array('sms', $channels, true)) {
                $this->deliverSms(
                    $sub, $tenant, SubscriptionReminder::TYPE_EXPIRY, $daysOut, $sub->ends_at,
                    SmsTemplate::bodyFor(SmsTemplate::TRIGGER_EXPIRING_SOON, $tenant->locale ?? null, $setting->reminderSmsTemplate()),
                    "Expiry SMS (T-{$daysOut}d)"
                );
            }
        }
    }

    /**
     * Send a reminder ahead of trial expiry, on each configured offset day,
     * through every enabled channel.
     */
    protected function sendTrialReminders(GeneralSetting $setting): void
    {
        if (! $setting->trialRemindersEnabled()) {
            return;
        }

        $channels = $this->deliverableChannels($setting);
        if (empty($channels)) {
            return;
        }

        $offsets   = $setting->trialReminderOffsets();
        $maxOffset = max($offsets);

        $subscriptions = TenantSubscription::where('status', TenantSubscription::STATUS_TRIAL)
            ->whereNotNull('trial_ends_at')
            ->whereDate('trial_ends_at', '>', now())
            ->whereDate('trial_ends_at', '<=', now()->addDays($maxOffset))
            ->with('tenant', 'plan')
            ->get();

        foreach ($subscriptions as $sub) {
            $tenant = $sub->tenant;
            if (! $tenant) {
                continue;
            }

            $daysOut = $this->daysUntil($sub->trial_ends_at);
            if (! in_array($daysOut, $offsets, true)) {
                continue;
            }

            if (in_array('email', $channels, true)) {
                $this->deliverEmail(
                    $sub, $tenant, SubscriptionReminder::TYPE_TRIAL, $daysOut, $sub->trial_ends_at,
                    fn () => EmailNotificationService::trialEnding($tenant),
                    "Trial email (T-{$daysOut}d)"
                );
            }

            if (in_array('sms', $channels, true)) {
                $this->deliverSms(
                    $sub, $tenant, SubscriptionReminder::TYPE_TRIAL, $daysOut, $sub->trial_ends_at,
                    SmsTemplate::bodyFor(SmsTemplate::TRIGGER_TRIAL_ENDING, $tenant->locale ?? null, $setting->trialSmsTemplate()),
                    "Trial SMS (T-{$daysOut}d)"
                );
            }
        }
    }

    /**
     * Finalize every ACTIVE/CANCELLED/SUSPENDED subscription whose ends_at
     * has passed (by calendar date, matching the previous whereDate
     * semantics) to EXPIRED.
     *
     * SUSPENDED is included because EnsureActiveSubscription never grants
     * access to it in the first place — it's already access-less, so
     * "terminal state" here is only about not leaving it stuck forever with
     * a stale cancellation_requested_at once its own ends_at has lapsed.
     * EXPIRED is the existing terminal state already used for the other two
     * branches; nothing new is introduced.
     *
     * Cron vs. cancel/resume/Paddle-webhook concurrency: this only scans for
     * candidate IDs up front. Every decision and write happens inside
     * finalizeExpiredSubscription(), under the SAME 'billing:cancel:{id}'
     * lock used by cancel/resume/sync/transaction handlers, re-reading with
     * lockForUpdate() so a stale pre-lock snapshot can never decide or
     * overwrite a concurrent writer's committed state. A subscription that
     * no longer qualifies by the time the lock is acquired (paid, resumed,
     * cancellation confirmed, etc. in the meantime) is safely skipped — this
     * also makes repeated cron runs idempotent, since a row already EXPIRED
     * is filtered out by the initial scan on the next run.
     */
    protected function checkExpired(): void
    {
        $candidateIds = TenantSubscription::whereIn('status', [
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_CANCELLED,
                TenantSubscription::STATUS_SUSPENDED,
            ])
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<', now())
            ->pluck('id');

        foreach ($candidateIds as $id) {
            $outcome = $this->finalizeExpiredSubscription((int) $id);
            if ($outcome === null) {
                continue;
            }

            [$sub, $tenant, $wasPendingCancellation, $originalStatus] = $outcome;

            // A confirmed cancellation reaching its end, or a still-live
            // (ACTIVE/SUSPENDED) subscription whose cancellation was
            // scheduled but never confirmed before this cron ran, are both
            // "the plan ended because the tenant asked it to" — not a plain
            // unexpected expiry.
            if ($originalStatus === TenantSubscription::STATUS_CANCELLED || $wasPendingCancellation) {
                $this->deliverEmail(
                    $sub, $tenant, SubscriptionReminder::TYPE_PLAN_ENDED, 0, $sub->ends_at,
                    fn () => EmailNotificationService::planEnded($tenant),
                    $originalStatus === TenantSubscription::STATUS_CANCELLED
                        ? 'Plan-ended notice (was cancelled)'
                        : "Plan-ended notice (was pending cancellation, {$originalStatus})"
                );
                continue;
            }

            $this->deliverEmail(
                $sub, $tenant, SubscriptionReminder::TYPE_EXPIRED, 0, $sub->ends_at,
                fn () => EmailNotificationService::subscriptionExpired($tenant),
                $originalStatus === TenantSubscription::STATUS_SUSPENDED
                    ? 'Expired notice (was suspended)'
                    : 'Expired notice'
            );
        }
    }

    /**
     * @return array{0: TenantSubscription, 1: Tenant, 2: bool, 3: string}|null
     */
    private function finalizeExpiredSubscription(int $id): ?array
    {
        $lockTarget = TenantSubscription::find($id);
        if (! $lockTarget) {
            return null;
        }

        return $this->withSubscriptionLock($lockTarget, function () use ($id): ?array {
            return DB::connection('central')->transaction(function () use ($id): ?array {
                // A locking read here — inside a transaction whose first
                // statement this is — always returns InnoDB's current
                // committed row regardless of REPEATABLE READ, and no other
                // writer can be mid-write against it (they'd be blocked on
                // the same Cache lock).
                $sub = TenantSubscription::whereKey($id)->lockForUpdate()->first();
                if (! $sub) {
                    return null;
                }

                if (! in_array($sub->status, [
                    TenantSubscription::STATUS_ACTIVE,
                    TenantSubscription::STATUS_CANCELLED,
                    TenantSubscription::STATUS_SUSPENDED,
                ], true)) {
                    return null;
                }

                if (! $sub->ends_at || ! $sub->ends_at->copy()->startOfDay()->lt(now()->startOfDay())) {
                    return null;
                }

                $tenant = $sub->tenant;
                if (! $tenant) {
                    return null;
                }

                $wasPendingCancellation = $sub->cancellation_requested_at !== null;
                $originalStatus = $sub->status;

                $sub->update([
                    'status' => TenantSubscription::STATUS_EXPIRED,
                    'cancellation_requested_at' => null,
                ]);

                return [$sub, $tenant, $wasPendingCancellation, $originalStatus];
            });
        });
    }

    /**
     * Lock contention here means a customer/admin action or a Paddle webhook
     * is mid-flight against this exact subscription right now — leave it
     * alone and pick it up on the next scheduled run rather than blocking
     * the rest of the batch or forcing a decision on stale data.
     */
    private function onSubscriptionLockTimeout(): mixed
    {
        Log::warning('CheckSubscriptionExpiry: could not acquire subscription lock; a concurrent billing action is in progress. Will retry next run.');

        return null;
    }

    /**
     * Send one email reminder, guarding against duplicates and recording the
     * outcome in the audit log.
     */
    protected function deliverEmail(
        TenantSubscription $sub,
        Tenant $tenant,
        string $type,
        int $offsetDays,
        ?Carbon $referenceDate,
        callable $send,
        string $label
    ): void {
        $channel = SubscriptionReminder::CHANNEL_EMAIL;
        $ref     = $referenceDate?->toDateString();

        if (SubscriptionReminder::alreadySent($sub->id, $type, $channel, $offsetDays, $ref)) {
            return;
        }

        $sent = (bool) $send();

        $this->record(
            $sub, $tenant, $type, $channel, $offsetDays, $ref, $tenant->admin_email,
            $sent ? SubscriptionReminder::STATUS_SENT : SubscriptionReminder::STATUS_FAILED,
            $sent ? null : 'Email was not sent (missing template, recipient, or send error).'
        );

        $this->info("{$label} for tenant {$tenant->id}: " . ($sent ? 'sent' : 'failed'));
    }

    /**
     * Send one SMS reminder. Tenants without a phone on file are recorded as
     * skipped rather than failed.
     */
    protected function deliverSms(
        TenantSubscription $sub,
        Tenant $tenant,
        string $type,
        int $offsetDays,
        ?Carbon $referenceDate,
        string $template,
        string $label
    ): void {
        $channel = SubscriptionReminder::CHANNEL_SMS;
        $ref     = $referenceDate?->toDateString();

        if (SubscriptionReminder::alreadySent($sub->id, $type, $channel, $offsetDays, $ref)) {
            return;
        }

        $phone = trim((string) ($tenant->owner_phone ?? ''));

        if ($phone === '') {
            $this->record(
                $sub, $tenant, $type, $channel, $offsetDays, $ref, null,
                SubscriptionReminder::STATUS_SKIPPED, 'No owner phone on file.'
            );
            $this->info("{$label} for tenant {$tenant->id}: skipped (no phone)");
            return;
        }

        $message = $this->buildSmsMessage($template, $tenant, $sub, $offsetDays, $referenceDate);
        $sent    = CentralSmsSender::send($phone, $message);

        $this->record(
            $sub, $tenant, $type, $channel, $offsetDays, $ref, $phone,
            $sent ? SubscriptionReminder::STATUS_SENT : SubscriptionReminder::STATUS_FAILED,
            $sent ? null : 'SMS not sent (gateway not configured or send error).'
        );

        $this->info("{$label} for tenant {$tenant->id}: " . ($sent ? 'sent' : 'failed'));
    }

    /**
     * Persist (or update) the audit row for a reminder attempt. Keyed on the
     * idempotency tuple so a retry overwrites the previous attempt.
     */
    protected function record(
        TenantSubscription $sub,
        Tenant $tenant,
        string $type,
        string $channel,
        int $offsetDays,
        ?string $referenceDate,
        ?string $target,
        string $status,
        ?string $error
    ): void {
        SubscriptionReminder::updateOrCreate(
            [
                'tenant_subscription_id' => $sub->id,
                'type'                   => $type,
                'channel'                => $channel,
                'offset_days'            => $offsetDays,
                'reference_date'         => $referenceDate,
            ],
            [
                'tenant_id' => $tenant->id,
                'target'    => $target,
                'status'    => $status,
                'error'     => $error,
                'sent_at'   => now(),
            ]
        );
    }

    /**
     * Build an SMS body from a template with {company} {plan} {date} {days}
     * {app} placeholders.
     */
    protected function buildSmsMessage(
        string $template,
        Tenant $tenant,
        TenantSubscription $sub,
        int $offsetDays,
        ?Carbon $referenceDate
    ): string {
        return strtr($template, [
            '{company}' => $tenant->company_name ?? $tenant->id,
            '{plan}'    => $sub->plan?->name ?? 'N/A',
            '{date}'    => $referenceDate?->format('M d, Y') ?? 'N/A',
            '{days}'    => (string) $offsetDays,
            '{app}'     => config('app.name', 'Stocky'),
        ]);
    }

    /**
     * Whole days from the start of today to the start of the given date.
     */
    protected function daysUntil(Carbon $date): int
    {
        return (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }
}
