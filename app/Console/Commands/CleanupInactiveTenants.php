<?php

namespace App\Console\Commands;

use App\Models\Central\TenantSubscription;
use App\Services\EmailNotificationService;
use App\Tenant;
use Illuminate\Console\Command;

class CleanupInactiveTenants extends Command
{
    protected $signature = 'tenant:cleanup-inactive
                            {--suspend-after-days=7 : Suspend tenants X days after subscription expires}
                            {--delete-after-days=90 : Delete tenants inactive for X days (never activated or long abandoned)}
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Suspend tenants with expired subscriptions and delete long-inactive/abandoned tenants';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $suspendDays = (int) $this->option('suspend-after-days');
        $deleteDays = (int) $this->option('delete-after-days');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        $this->suspendExpired($suspendDays, $dryRun);
        $this->deleteAbandoned($deleteDays, $dryRun);

        $this->info('Cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * Suspend active tenants whose subscription expired X days ago.
     */
    protected function suspendExpired(int $days, bool $dryRun): void
    {
        $this->info("--- Checking for tenants to suspend (expired > {$days} days) ---");

        $tenants = Tenant::where('status', Tenant::STATUS_ACTIVE)
            ->whereHas('subscription', function ($q) use ($days) {
                $q->where('status', TenantSubscription::STATUS_EXPIRED)
                  ->where('ends_at', '<', now()->subDays($days));
            })
            ->whereDoesntHave('subscription', function ($q) {
                $q->whereIn('status', [
                    TenantSubscription::STATUS_ACTIVE,
                    TenantSubscription::STATUS_TRIAL,
                ]);
            })
            ->with('domains')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('  No tenants to suspend.');
            return;
        }

        foreach ($tenants as $tenant) {
            $domain = $tenant->domains->first()?->domain ?? $tenant->id;
            $this->line("  Suspending: {$tenant->company_name} ({$domain})");

            if (! $dryRun) {
                // Snapshot pre-suspension status so an admin reactivate() restores the real prior state.
                $tenant->status_before_suspension = $tenant->status;
                $tenant->status = Tenant::STATUS_SUSPENDED;
                $tenant->save();
            }
        }

        $this->info("  " . ($dryRun ? 'Would suspend' : 'Suspended') . ": {$tenants->count()} tenant(s).");
    }

    /**
     * Delete tenants that are truly abandoned:
     * - Never had any activity (last_activity_at is null) and created > X days ago
     * - OR last activity was > X days ago AND subscription is expired/none
     */
    protected function deleteAbandoned(int $days, bool $dryRun): void
    {
        $this->info("--- Checking for abandoned tenants to delete (inactive > {$days} days) ---");

        $cutoff = now()->subDays($days);

        $tenants = Tenant::where(function ($q) use ($cutoff) {
                // Never used and created long ago
                $q->where(function ($q2) use ($cutoff) {
                    $q2->whereNull('last_activity_at')
                       ->where('created_at', '<', $cutoff);
                })
                // Or last activity was long ago
                ->orWhere('last_activity_at', '<', $cutoff);
            })
            // Only delete suspended/cancelled/failed tenants, not active ones
            ->whereIn('status', [
                Tenant::STATUS_SUSPENDED,
                Tenant::STATUS_CANCELLED,
                Tenant::STATUS_FAILED,
            ])
            ->with('domains')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('  No abandoned tenants to delete.');
            return;
        }

        foreach ($tenants as $tenant) {
            $domain = $tenant->domains->first()?->domain ?? $tenant->id;
            $lastSeen = $tenant->last_activity_at ? $tenant->last_activity_at->diffForHumans() : 'never';
            $this->line("  Deleting: {$tenant->company_name} ({$domain}) — last seen: {$lastSeen}");

            if (! $dryRun) {
                // Clean up related records, then delete tenant (triggers DB drop via stancl/tenancy)
                $tenant->subscriptions()->delete();
                $tenant->payments()->delete();
                $tenant->domains()->delete();
                $tenant->delete();
            }
        }

        $this->info("  " . ($dryRun ? 'Would delete' : 'Deleted') . ": {$tenants->count()} tenant(s).");
    }
}
