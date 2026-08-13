<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

/**
 * Use this trait on jobs that touch tenant models so they run in the correct tenant context.
 *
 * - When the job is dispatched from a tenant context (e.g. web request on a tenant domain),
 *   stancl/tenancy's QueueTenancyBootstrapper injects tenant_id into the payload and
 *   initializes tenancy when the job is processed. No extra work needed.
 *
 * - When the job is dispatched from central/console (e.g. scheduler or artisan without tenant),
 *   pass the tenant id in the constructor and set $this->tenantId. This trait will
 *   initialize tenancy at the start of handle() so the job runs in that tenant's DB.
 */
trait TenantAwareJob
{
    /**
     * Optional tenant id. Set in constructor when dispatching from central/console
     * so the job runs in that tenant's context.
     *
     * @var string|null
     */
    public $tenantId = null;

    /**
     * Ensure tenancy is initialized before running job logic.
     * Call this at the start of your handle() method.
     */
    protected function ensureTenantContext(): void
    {
        if (tenancy()->initialized) {
            return;
        }

        $id = $this->tenantId ?? $this->tenant_id ?? null;
        if ($id) {
            tenancy()->initialize(tenancy()->find($id));
        }
    }
}
