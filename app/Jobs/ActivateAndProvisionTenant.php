<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Activated after a successful payment for a pending (paid-plan) tenant.
 * Creates the subdomain, marks the tenant as provisioning, and
 * delegates the heavy lifting to ProvisionTenantWorkspace.
 */
class ActivateAndProvisionTenant implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $tenantId,
        public int $paymentId,
    ) {}

    public function handle(): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('id', $this->tenantId)->first();
        if (! $tenant) {
            Log::warning("ActivateAndProvisionTenant: tenant {$this->tenantId} not found");
            return;
        }

        if (in_array($tenant->status ?? '', ['active', 'provisioning'], true)) {
            Log::info("ActivateAndProvisionTenant: tenant {$this->tenantId} already {$tenant->status}, skipping");
            return;
        }

        try {
            $subdomain = $tenant->getInternal('pending_subdomain');

            if ($subdomain && ! $tenant->domains()->where('domain', $subdomain)->exists()) {
                $tenant->createDomain($subdomain);
            }

            $tenant->status = 'provisioning';
            $tenant->save();

            ProvisionTenantWorkspace::dispatch($this->tenantId);

            Log::info("ActivateAndProvisionTenant: provisioning started for tenant {$this->tenantId}");
        } catch (Throwable $e) {
            $tenant->status = 'failed';
            $tenant->setInternal('provision_error', $e->getMessage());
            $tenant->save();

            $payment = TenantBillingPayment::find($this->paymentId);
            if ($payment?->subscription) {
                $payment->subscription->update(['status' => TenantSubscription::STATUS_FAILED]);
            }

            report($e);
        }
    }
}
