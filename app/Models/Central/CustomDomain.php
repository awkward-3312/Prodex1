<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomDomain extends Model
{
    protected $connection = 'central';

    protected $table = 'custom_domains';

    public const SSL_PENDING = 'pending';
    public const SSL_ACTIVE  = 'active';
    public const SSL_FAILED  = 'failed';
    public const SSL_UNKNOWN = 'unknown';

    protected $fillable = [
        'tenant_id',
        'domain',
        'verification_token',
        'is_verified',
        'verified_at',
        'last_check_at',
        'last_check_error',
        'ssl_status',
        'is_primary',
    ];

    protected $casts = [
        'is_verified'   => 'boolean',
        'is_primary'    => 'boolean',
        'verified_at'   => 'datetime',
        'last_check_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function markVerified(): void
    {
        $this->forceFill([
            'is_verified'      => true,
            'verified_at'      => now(),
            'last_check_at'    => now(),
            'last_check_error' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'is_verified'      => false,
            'last_check_at'    => now(),
            'last_check_error' => mb_substr($reason, 0, 500),
        ])->save();
    }
}
