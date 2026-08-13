<?php

declare(strict_types=1);

namespace App;

use App\Models\Central\CustomDomain;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Illuminate\Support\Facades\File;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected static function booted()
    {
        static::created(function (self $tenant) {
            $base = storage_path('tenant' . $tenant->id);
            foreach ([
                $base . '/app/public',
                $base . '/framework/cache/data',
                $base . '/framework/sessions',
                $base . '/framework/testing',
                $base . '/framework/views',
                $base . '/logs',
            ] as $dir) {
                File::ensureDirectoryExists($dir, 0755, true);
            }
        });
    }

    protected $casts = [
        'last_activity_at'    => 'datetime',
        'tenancy_db_password' => 'encrypted',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'status',
            'last_activity_at',
            'created_at',
            'updated_at',
            'data',
        ];
    }

    public const STATUS_PENDING      = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_SUSPENDED    = 'suspended';
    public const STATUS_CANCELLED    = 'cancelled';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_REJECTED     = 'rejected';

    public function subscription()
    {
        // Tenants accumulate subscription rows over time (trial, upgrades) —
        // "the subscription" is always the most recent one.
        return $this->hasOne(TenantSubscription::class, 'tenant_id', 'id')
            ->latest('id');
    }

    public function subscriptions()
    {
        return $this->hasMany(TenantSubscription::class, 'tenant_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(TenantBillingPayment::class, 'tenant_id', 'id');
    }

    public function customDomains()
    {
        return $this->hasMany(CustomDomain::class, 'tenant_id', 'id');
    }

    public function activeSubscription()
    {
        // A tenant can hold several matching rows (e.g. an old trial plus a newly
        // activated paid subscription) — always resolve to the most recent one.
        return $this->hasOne(TenantSubscription::class, 'tenant_id', 'id')
            ->whereIn('status', [
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_TRIAL,
            ])
            ->latest('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PROVISIONING;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function getTenantUrl(): string
    {
        $domain = $this->domains()->first();

        if (! $domain) {
            return '';
        }

        $hostname = $domain->domain;

        // If the stored domain is a bare subdomain (no dots), append the central base domain
        if (! str_contains($hostname, '.')) {
            $baseDomain = preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost');
            $hostname = "{$hostname}.{$baseDomain}";
        }

        $protocol = request()->secure() ? 'https' : 'http';
        $port = request()->getPort();
        $portSuffix = ($port && ! in_array($port, [80, 443])) ? ":{$port}" : '';

        return "{$protocol}://{$hostname}{$portSuffix}";
    }

    public function getLoginUrl(): string
    {
        $base = $this->getTenantUrl();
        return $base ? "{$base}/login" : '';
    }

    // ── Database credentials ──────────────────────────────────────────
    // tenancy_db_password uses Laravel's `encrypted` cast so reads return plaintext
    // transparently — required because stancl/tenancy's DatabaseConfig reads the
    // raw attribute when building the tenant connection.

    public function setDatabaseCredentials(string $host, string $name, string $username, string $password, int $port = 3306): void
    {
        $this->tenancy_db_host     = $host;
        $this->tenancy_db_name     = $name;
        $this->tenancy_db_username = $username;
        $this->tenancy_db_password = $password;
        $this->tenancy_db_port     = $port;
        $this->save();
    }

    public function getDatabaseCredentials(): ?array
    {
        if (! $this->tenancy_db_name) {
            return null;
        }

        return [
            'host'     => $this->tenancy_db_host ?? '127.0.0.1',
            'database' => $this->tenancy_db_name,
            'username' => $this->tenancy_db_username ?? 'root',
            'password' => (string) ($this->tenancy_db_password ?? ''),
            'port'     => (int) ($this->tenancy_db_port ?? 3306),
        ];
    }

    /**
     * Whether this tenant has admin-provided database credentials.
     */
    public function hasDatabaseCredentials(): bool
    {
        return ! empty($this->tenancy_db_host) && ! empty($this->tenancy_db_name) && ! empty($this->tenancy_db_username);
    }

    /**
     * Get effective DB credentials (custom if set, otherwise derived from central config).
     * Works for both shared-hosting (custom) and VPS (auto-provisioned) tenants.
     */
    public function getEffectiveDatabaseCredentials(): array
    {
        // Custom credentials (shared hosting / admin-provided)
        if ($this->hasDatabaseCredentials()) {
            return $this->getDatabaseCredentials();
        }

        // Auto-provisioned (VPS): derive DB name from tenancy config pattern, credentials from central config
        $dbName = $this->tenancy_db_name
            ?: (config('tenancy.database.prefix', 'tenant') . $this->id . config('tenancy.database.suffix', ''));

        return [
            'host'     => config('database.connections.central.host', '127.0.0.1'),
            'database' => $dbName,
            'username' => config('database.connections.central.username', 'root'),
            'password' => config('database.connections.central.password', ''),
            'port'     => (int) config('database.connections.central.port', 3306),
        ];
    }
}
