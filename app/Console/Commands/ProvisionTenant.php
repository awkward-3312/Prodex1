<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionTenantWorkspace;
use App\Tenant;
use Illuminate\Console\Command;

class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision {tenantId}';
    protected $description = 'Provision a tenant workspace (create DB, migrate, seed, create admin)';

    public function handle(): int
    {
        $tenantId = $this->argument('tenantId');
        $tenant   = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant {$tenantId} not found.");
            return 1;
        }

        if ($tenant->status === 'active') {
            $this->info("Tenant {$tenantId} is already active.");
            return 0;
        }

        $this->info("Starting provisioning for tenant {$tenantId}...");

        $job = new ProvisionTenantWorkspace($tenantId);
        $job->handle();

        $tenant->refresh();
        if ($tenant->status === 'active') {
            $this->info("Tenant {$tenantId} provisioned successfully.");
            return 0;
        }

        $this->error("Provisioning failed. Status: {$tenant->status}");
        return 1;
    }
}
