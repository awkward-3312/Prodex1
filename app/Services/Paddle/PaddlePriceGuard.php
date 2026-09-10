<?php

declare(strict_types=1);

namespace App\Services\Paddle;

use App\Models\Central\TenantSubscription;
use RuntimeException;

/**
 * Server-side invariant for Paddle catalog mapping.
 *
 * Browser checkout data is never authoritative. Any Paddle subscription or
 * transaction that is linked to a PRODEX subscription must carry the exact
 * Price ID configured for that local plan + billing cycle.
 */
class PaddlePriceGuard
{
    public function assertMatchesSubscription(TenantSubscription $subscription, ?string $actualPriceId): void
    {
        $actual = trim((string) $actualPriceId);
        $expected = $this->expectedPriceId($subscription);

        if ($actual === '' || $expected === '' || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Paddle price does not match the authenticated PRODEX checkout.');
        }
    }

    public function expectedPriceId(TenantSubscription $subscription): string
    {
        $plan = $subscription->relationLoaded('plan')
            ? $subscription->plan
            : $subscription->plan()->first();

        // Paddle is intentionally limited to Emprendedor until the remaining
        // PRODEX plans have real Paddle Price IDs configured in Super Admin.
        if (! $plan || (int) $plan->id !== 1 || (string) $plan->slug !== 'starter') {
            return '';
        }

        if (! in_array((string) $subscription->billing_cycle, ['monthly', 'yearly'], true)) {
            return '';
        }

        $configKey = $subscription->billing_cycle === 'yearly'
            ? 'services.paddle.starter_yearly_price_id'
            : 'services.paddle.starter_monthly_price_id';

        return trim((string) config($configKey, ''));
    }
}
