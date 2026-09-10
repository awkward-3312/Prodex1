<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Services\Paddle\PaddlePriceGuard;
use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PaddleSubscription extends Model
{
    protected $connection = 'central';

    protected $table = 'paddle_subscriptions';

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'paddle_subscription_id',
        'paddle_customer_id',
        'paddle_price_id',
        'status',
        'next_billed_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'scheduled_change',
        'custom_data',
        'last_event_at',
    ];

    protected $casts = [
        'next_billed_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'scheduled_change' => 'array',
        'custom_data' => 'array',
        'last_event_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            // Enforce the catalog mapping at persistence level so no webhook
            // path can bind a cheaper/different Paddle price to a PRODEX plan.
            if ($mapping->exists && ! $mapping->isDirty('paddle_price_id')) {
                return;
            }

            $subscription = TenantSubscription::find($mapping->tenant_subscription_id);
            if (! $subscription) {
                throw new RuntimeException('Paddle mapping references an unknown PRODEX subscription.');
            }

            app(PaddlePriceGuard::class)->assertMatchesSubscription(
                $subscription,
                $mapping->paddle_price_id
            );
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function subscription()
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }
}
