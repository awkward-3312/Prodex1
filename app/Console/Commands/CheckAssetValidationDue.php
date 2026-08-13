<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\AssetValidationDueNotification;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAssetValidationDue extends Command
{
    protected $signature = 'assets:check-validation-due';

    protected $description = 'Notify admins when asset next_validation is within 5 working days or overdue';

    /**
     * Add working days to a date (excluding Saturday and Sunday).
     *
     * @param  \Carbon\Carbon  $date
     * @param  int  $days
     * @return \Carbon\Carbon
     */
    protected function addWorkingDays(Carbon $date, int $days): Carbon
    {
        $d = $date->copy();
        $added = 0;
        while ($added < $days) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $added++;
            }
        }

        return $d;
    }

    /**
     * Check if a date is within the next N working days from today (inclusive) or in the past.
     *
     * @param  \Carbon\Carbon  $nextValidation
     * @param  int  $workingDays
     * @return bool
     */
    protected function isDueWithinWorkingDays(Carbon $nextValidation, int $workingDays = 5): bool
    {
        $today = Carbon::today();
        $limit = $this->addWorkingDays($today, $workingDays);
        $nextValidation->startOfDay();

        return $nextValidation->lte($limit);
    }

    public function handle()
    {
        // Assets live in each tenant DB — this must run per tenant,
        // never on the central connection.
        foreach (Tenant::where('status', Tenant::STATUS_ACTIVE)->cursor() as $tenant) {
            try {
                $tenant->run(fn () => $this->processTenant($tenant));
            } catch (\Illuminate\Database\QueryException $e) {
                // Tenant DB not migrated for the assets feature yet — skip quietly.
                if (str_contains($e->getMessage(), 'Base table or view not found')) {
                    continue;
                }
                Log::warning("assets:check-validation-due failed for tenant {$tenant->id}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                Log::warning("assets:check-validation-due failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        return 0;
    }

    protected function processTenant(Tenant $tenant): void
    {
        $assets = Asset::whereNull('deleted_at')
            ->whereNotNull('next_validation')
            ->get()
            ->filter(function (Asset $asset) {
                return $this->isDueWithinWorkingDays($asset->next_validation->copy(), 5);
            });

        if ($assets->isEmpty()) {
            return;
        }

        $permission = Permission::where('name', 'assets')->first();
        if (! $permission) {
            $this->warn("[{$tenant->id}] Permission \"assets\" not found. No notifications sent.");

            return;
        }

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        if (empty($roleIds)) {
            return;
        }

        $users = User::whereNull('deleted_at')
            ->whereHas('roles', function ($q) use ($roleIds) {
                $q->whereIn('roles.id', $roleIds);
            })
            ->get()
            ->unique('id');

        if ($users->isEmpty()) {
            return;
        }

        foreach ($assets as $asset) {
            foreach ($users as $user) {
                $user->notify(new AssetValidationDueNotification($asset));
            }
        }

        $this->info(sprintf(
            '[%s] Sent %d notification(s) to %d user(s) for %d asset(s) due for validation.',
            $tenant->id,
            $assets->count() * $users->count(),
            $users->count(),
            $assets->count()
        ));
    }
}
