<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Runs in tenant context after SeedDatabase. Updates the seeded admin user (id 1)
 * with the tenant's admin email and password from tenant->data.
 */
class CreateTenantAdminFromData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantWithDatabase */
    protected $tenant;

    public function __construct(TenantWithDatabase $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(): void
    {
        $this->tenant->run(function () {
            $email = $this->tenant->getInternal('admin_email');
            $passwordHash = $this->tenant->getInternal('admin_password_hash');
            $companyName = $this->tenant->getInternal('company_name') ?? 'Admin';

            if (! $email || ! $passwordHash) {
                return;
            }

            $user = User::find(1);
            if ($user) {
                $user->update([
                    'email' => $email,
                    'password' => $passwordHash,
                    'username' => $companyName,
                    'firstname' => explode(' ', $companyName)[0] ?? 'Admin',
                    'lastname' => explode(' ', $companyName)[1] ?? '',
                ]);
            }

            // Remove sensitive data from tenant
            $this->tenant->setInternal('admin_email', null);
            $this->tenant->setInternal('admin_password_hash', null);
            $this->tenant->setInternal('company_name', null);
            $this->tenant->save();
        });
    }
}
